<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\OperatorRole;
use App\Models\AuditEntry;
use App\Models\User;

test('changing an operator\'s authority records what changed and who did it', function (): void {
    // The first entry in this trail that names an actor. Registration and
    // self-removal both record none, for reasons of their own, so until a role
    // could change there was no path through the recorder that answered the
    // "who" in "who changed what" with an operator.
    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->create(['email' => 'promoted@example.test']);

    $this->actingAs($owner);

    $staff->role = OperatorRole::Owner;
    $staff->save();

    $entry = AuditEntry::query()->where('event', AuditEvent::OperatorRoleChanged->value)->sole();

    expect($entry->subject_id)->toBe($staff->getKey())
        ->and($entry->subject_label)->toBe('promoted@example.test')
        ->and($entry->changes)->toBe(['role' => ['from' => 'staff', 'to' => 'owner']])
        ->and($entry->actor_id)->toBe($owner->getKey())
        ->and($entry->actor_label)->toBe('owner@example.test')
        ->and($entry->created_at)->not->toBeNull();
});

test('renaming an operator is not recorded, while changing their authority is', function (): void {
    // The pairing again, on the event most able to record too much: every save
    // on an operator reaches the observer, so the filter that keeps a rename
    // out of the trail is one line and would fail silently. A test asserting
    // only that the rename went unrecorded would pass against an observer that
    // handles no updates at all, or against no observer whatsoever -- so the
    // authority change follows in the same run, through the same recorder.
    $operator = User::factory()->create(['email' => 'renamed@example.test']);

    $baseline = AuditEntry::query()->count();

    $operator->name = 'A Different Name';
    $operator->save();

    expect(AuditEntry::query()->count())->toBe($baseline);

    $operator->role = OperatorRole::Owner;
    $operator->save();

    expect(AuditEntry::query()->count())->toBe($baseline + 1)
        ->and(AuditEntry::query()->orderByDesc('id')->first()?->event)
        ->toBe(AuditEvent::OperatorRoleChanged);
});

test('a save that changes the authority and something else records only the authority', function (): void {
    // Guards the filter's shape rather than its existence. Filtering on "was
    // this the only thing that changed" would read almost identically and would
    // drop the entry here -- the case where someone's authority moves as part
    // of a larger edit, which is exactly when a quiet omission matters most.
    $operator = User::factory()->create(['email' => 'both@example.test']);

    $baseline = AuditEntry::query()->count();

    $operator->name = 'Renamed And Promoted';
    $operator->role = OperatorRole::Owner;
    $operator->save();

    $entry = AuditEntry::query()->where('event', AuditEvent::OperatorRoleChanged->value)->sole();

    expect(AuditEntry::query()->count())->toBe($baseline + 1)
        ->and($entry->changes)->toBe(['role' => ['from' => 'staff', 'to' => 'owner']]);
});

test('a save that leaves the authority where it was records nothing', function (): void {
    $operator = User::factory()->owner()->create(['email' => 'unchanged@example.test']);

    $baseline = AuditEntry::query()->count();

    // Assigned the role it already holds, so the column is written but nothing
    // about the operator's authority actually moved.
    $operator->role = OperatorRole::Owner;
    $operator->save();

    expect(AuditEntry::query()->count())->toBe($baseline);

    // Paired, so this cannot be satisfied by a recorder that is simply off.
    $operator->role = OperatorRole::Staff;
    $operator->save();

    expect(AuditEntry::query()->count())->toBe($baseline + 1)
        ->and(AuditEntry::query()->orderByDesc('id')->first()?->changes)
        ->toBe(['role' => ['from' => 'owner', 'to' => 'staff']]);
});

test('every audit event the vocabulary defines has something that produces it', function (): void {
    // Step 8's sweep of the permission enum against the gate registry, in the
    // shape this step needs. Every other test here exercises an event someone
    // remembered to write a test for; this one drives all three roster changes
    // and compares what came out against the enum itself, so a case added to
    // the vocabulary with nothing recording it fails here and nowhere else.
    //
    // A case with no producer is not an inert placeholder. It reads, to anyone
    // looking at the enum to learn what the trail contains, as a promise that
    // the trail answers a question it never answers.
    $operator = User::factory()->create(['email' => 'swept@example.test']);

    $operator->role = OperatorRole::Owner;
    $operator->save();

    $operator->delete();

    $produced = AuditEntry::query()->get()
        ->map(fn (AuditEntry $entry): string => $entry->event->value)
        ->unique()->sort()->values()->all();

    $defined = collect(AuditEvent::cases())
        ->map(fn (AuditEvent $event): string => $event->value)
        ->sort()->values()->all();

    expect($produced)->toBe($defined);
});
