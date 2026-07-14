<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the campaign database carries the audit trail', function (): void {
    expect(Schema::hasTable('audit_entries'))->toBeTrue()
        ->and(Schema::hasColumns('audit_entries', [
            'event',
            'subject_type',
            'subject_id',
            'subject_label',
            'actor_id',
            'actor_label',
            'changes',
            'created_at',
        ]))->toBeTrue();
});

// The matching claim -- that the central database carries no audit trail --
// deliberately lives in tests/Feature/CentralSchemaTest.php rather than here.
// Asserting it from this suite looks natural and cannot fail: the campaign
// harness only rebuilds the central schema when it is missing entirely, so a
// misplaced central migration is never applied during this suite's run and the
// absence holds whether or not it is true of a freshly migrated database. It
// was written here first and caught by moving the migration into the central
// set and watching the assertion stay green. The Feature suite migrates central
// per test, so the claim can fail there.

test('an audit entry written in campaign context lands in the campaign database', function (): void {
    AuditEntry::create([
        'event' => AuditEvent::OperatorRegistered,
        'subject_type' => User::class,
        'subject_id' => 1,
        'subject_label' => 'first@example.test',
        'changes' => ['role' => ['from' => null, 'to' => 'owner']],
    ]);

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName());

    $this->assertDatabaseHas('audit_entries', ['subject_label' => 'first@example.test'], 'tenant');
});

test('an audit entry is never revised, so it carries no updated_at', function (): void {
    // Both halves matter. The schema half is the claim; the behavioural half is
    // what proves the model agrees with it, because an Eloquent model that
    // still believed in updated_at would fail to insert against this table at
    // all rather than quietly writing a column nobody reads.
    expect(Schema::hasColumn('audit_entries', 'updated_at'))->toBeFalse();

    $entry = AuditEntry::create([
        'event' => AuditEvent::OperatorRemoved,
        'subject_type' => User::class,
        'subject_id' => 7,
        'subject_label' => 'departed@example.test',
    ]);

    expect($entry->created_at)->not->toBeNull();
});

test('an audit entry outlives the operator it describes', function (): void {
    // What the deliberate absence of foreign keys buys, and the property the
    // whole trail depends on: the entry recording that an operator was removed
    // is written as that operator ceases to exist. A constraint on subject_id
    // would either delete the evidence with the subject or refuse the removal,
    // and both answers destroy the record of the thing worth recording.
    $operator = User::factory()->create(['email' => 'leaving@example.test']);

    $written = AuditEntry::create([
        'event' => AuditEvent::OperatorRemoved,
        'subject_type' => User::class,
        'subject_id' => $operator->getKey(),
        'subject_label' => $operator->email,
    ]);

    $operator->delete();

    // Scoped to the entry this test wrote. The roster observer records the same
    // operator arriving and leaving, so the table holds its entries too -- this
    // test is about what the schema permits, not about what the observer does,
    // and it should not start failing the next time the observer records more.
    $entry = AuditEntry::query()->whereKey($written->getKey())->sole();

    expect(User::query()->whereKey($entry->subject_id)->exists())->toBeFalse()
        ->and($entry->subject_label)->toBe('leaving@example.test');
});

test('an entry round-trips its event and its changes through the database', function (): void {
    $entry = AuditEntry::create([
        'event' => AuditEvent::OperatorRoleChanged,
        'subject_type' => User::class,
        'subject_id' => 3,
        'subject_label' => 'promoted@example.test',
        'actor_id' => 1,
        'actor_label' => 'owner@example.test',
        'changes' => ['role' => ['from' => 'staff', 'to' => 'owner']],
    ]);

    $reloaded = AuditEntry::query()->whereKey($entry->getKey())->sole();

    expect($reloaded->event)->toBeInstanceOf(AuditEvent::class)
        ->and($reloaded->event)->toBe(AuditEvent::OperatorRoleChanged)
        ->and($reloaded->changes)->toBe(['role' => ['from' => 'staff', 'to' => 'owner']]);

    // And the stored representation is the enum's backing value rather than a
    // serialization of the case object -- what any later reader, data migration
    // or hand-written query would have to match on.
    expect(DB::connection('tenant')->table('audit_entries')->where('id', $entry->getKey())->value('event'))
        ->toBe('operator-role-changed');
});
