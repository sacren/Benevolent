<?php

declare(strict_types=1);

namespace App\Audit;

use App\Authorization\OperatorRole;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the campaign's roster changes into its audit trail.
 *
 * An observer rather than calls placed at each site that changes the roster,
 * and the difference matters more than it looks. A call site records what
 * whoever wrote it remembered to record; the next path that creates an operator
 * — an invitation flow, a promotion screen, a console command, a seeder —
 * records nothing, and nothing about the omission is visible. The invitation
 * flow was the first of those to arrive (AcceptOperatorInvitation) and it
 * wrote no recording call of its own: it saves through Eloquent and this
 * observer answers for it. Observing the
 * model instead means the trail describes what happened to the operator table,
 * not what one author anticipated.
 *
 * The cost of that choice is accepted deliberately: this fires for factories
 * and seeders too. That is the right answer rather than a tolerated one — a
 * seeder really does create an operator, and a trail that hid the ones created
 * by unusual routes would be misleading in exactly the case worth examining.
 */
class OperatorAuditObserver
{
    /**
     * An operator came into existence inside this campaign.
     */
    public function created(User $operator): void
    {
        $this->record($operator, AuditEvent::OperatorRegistered, [
            // The authority they arrived with, which is the invitation's to
            // grant and the whole point of recording the arrival.
            'role' => ['from' => null, 'to' => $this->roleOf($operator)->value],
        ]);
    }

    /**
     * An operator's authority within this campaign changed.
     *
     * Every save on an operator arrives here -- a rename, an email edit, a new
     * password, a two-factor enrolment, a verification timestamp -- and all but
     * one are none of the trail's business. So the filter is on the attribute
     * rather than on the event: unless the role itself moved, nothing is
     * written. That single line is what separates this from a recorder that
     * logs everything an operator ever does, and a test pairs it against a
     * rename to keep it that way.
     *
     * The roster is the screen that produces one (ChangeOperatorRole). This
     * method was written before it, so promotion arrived already audited
     * rather than shipping the surface and the record of it separately, and
     * the roster's change is recorded with its Owner as the actor for no more
     * reason than that the request is authenticated.
     */
    public function updated(User $operator): void
    {
        if (! $operator->wasChanged('role')) {
            return;
        }

        $this->record($operator, AuditEvent::OperatorRoleChanged, [
            // The raw original rather than the cast one: this reads the value as
            // it was stored, which is what the entry records and what any later
            // reader of the trail will be matching on. It is still available at
            // this point because Eloquent re-syncs originals after firing the
            // updated event, not before it.
            'role' => [
                'from' => $operator->getRawOriginal('role'),
                'to' => $this->roleOf($operator)->value,
            ],
        ]);
    }

    /**
     * An operator ceased to exist inside this campaign.
     */
    public function deleted(User $operator): void
    {
        // No `changes`. Nothing about the operator was altered -- they stopped
        // existing, and the entry itself is the whole statement.
        $this->record($operator, AuditEvent::OperatorRemoved, null);
    }

    /**
     * The role the operator actually holds.
     *
     * A model created without a role named carries no role attribute, while the
     * row it just wrote carries the column's default. Reading the attribute
     * alone would record null for that operator and quietly understate the
     * trail; falling back to the enum's default reports what the database
     * stored, and a test already pins that default to the migration's literal
     * so the two cannot drift apart here either.
     */
    private function roleOf(User $operator): OperatorRole
    {
        return $operator->role ?? OperatorRole::default();
    }

    /**
     * Write one entry, attributing it to whoever is acting if anyone is.
     *
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes
     */
    private function record(User $subject, AuditEvent $event, ?array $changes): void
    {
        $actor = Auth::user();
        $actor = $actor instanceof User ? $actor : null;

        AuditEntry::create([
            'event' => $event,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            // Captured now and never refreshed, because by the time anyone reads
            // an entry recording a removal, the operator it names is gone.
            'subject_label' => $subject->email,
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor?->email,
            'changes' => $changes,
        ]);
    }
}
