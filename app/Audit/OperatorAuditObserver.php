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
 * records nothing, and nothing about the omission is visible. Every one of
 * those is a path this application does not have yet and will. Observing the
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
            // The authority they arrived with, which on an open campaign is the
            // whole point: the first operator to register claims it as Owner.
            'role' => ['from' => null, 'to' => $this->roleOf($operator)->value],
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
