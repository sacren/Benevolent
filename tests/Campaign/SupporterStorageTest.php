<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A statement PostgreSQL refuses aborts the transaction it was made in, and
 * this suite wraps every test body in one so that nothing a test writes
 * survives it. Running the refusal inside a nested transaction turns it into a
 * savepoint, so the refusal is rolled back to and the surrounding transaction —
 * along with every assertion after this call, and the harness's own rollback —
 * stays usable.
 */
function refusalFrom(Closure $write): ?QueryException
{
    try {
        DB::connection('tenant')->transaction($write);
    } catch (QueryException $refusal) {
        return $refusal;
    }

    return null;
}

test('the campaign database carries the supporter list', function (): void {
    expect(Schema::hasTable('supporters'))->toBeTrue()
        ->and(Schema::hasColumns('supporters', [
            'name',
            'given_name',
            'family_name',
            'email',
            'postcode',
            'subscription_status',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

// The matching claim -- that the central database carries no supporter list --
// deliberately lives in tests/Feature/CentralSchemaTest.php rather than here,
// for the reason L-18 records: this suite rebuilds the central schema only when
// it is missing entirely, so a migration misfiled into the central set is never
// applied during this run and the absence would hold whether or not it is true.

test('a supporter written in campaign context lands in the campaign database', function (): void {
    Supporter::factory()->create(['email' => 'first@example.test']);

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName());

    $this->assertDatabaseHas('supporters', ['email' => 'first@example.test'], 'tenant');
});

test('the factory builds a valid supporter', function (): void {
    $supporter = Supporter::factory()->create();

    $reloaded = Supporter::query()->whereKey($supporter->getKey())->sole();

    expect($reloaded->email)->not->toBeEmpty()
        ->and($reloaded->subscription_status)->toBe(SubscriptionStatus::Subscribed)
        // The default state is the row a split source produces, so the display
        // string is the join of the parts rather than anything inferred.
        ->and($reloaded->name)->toBe($reloaded->given_name.' '.$reloaded->family_name);
});

test('a name given as one string leaves the parts empty rather than guessing them', function (): void {
    // The whole of the name design, stated as behaviour. A source that handed
    // us one string told us nothing about where the boundary falls -- and for a
    // mononym, or a name presented family-name-first, there may be no boundary
    // to find -- so both parts stay null. Null here means "we were never told",
    // which is a fact a later import can correct; a guessed split is a
    // fabrication nothing downstream can tell from a real one.
    $supporter = Supporter::factory()->fromSingleStringSource()->create();

    $reloaded = Supporter::query()->whereKey($supporter->getKey())->sole();

    expect($reloaded->name)->not->toBeNull()
        ->and($reloaded->given_name)->toBeNull()
        ->and($reloaded->family_name)->toBeNull();
});

test('a supporter must have an address, and need not have a name', function (): void {
    // Both halves of the nullability design, in one place and in one direction
    // each, because either alone is satisfied by a schema that is wrong the
    // other way round.
    //
    // A row with no name at all is valid: a petition widget that asked only for
    // an email produces one, and that person is perfectly contactable.
    $nameless = Supporter::factory()->withoutName()->create(['email' => 'nameless@example.test']);

    expect($nameless->name)->toBeNull()
        ->and(Supporter::query()->whereKey($nameless->getKey())->exists())->toBeTrue();

    // A row with no address is not. It is neither contactable nor identifiable,
    // and this module has no second channel to reach anyone by. Written past
    // Eloquent so that the refusal can only be the database's.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('supporters')->insert([
        'name' => 'Unreachable',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23502 -- not-null violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $refusal->getCode())->toBe('23502');
});

test('a campaign holds one supporter per address, whatever its casing', function (): void {
    // D-8: the address is the identity, and casing is not part of who someone
    // is. A list that treats Jean@Example.test and jean@example.test as two
    // people has failed at precisely the variation real exports are full of, so
    // the constraint is on lower(email) rather than on the column.
    $first = Supporter::factory()->create(['email' => 'Jean.Sacren@Example.test']);

    $refusal = refusalFrom(fn () => Supporter::factory()->create(['email' => 'jean.sacren@example.test']));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23505 -- unique violation.
        ->and((string) $refusal->getCode())->toBe('23505');

    // The positive half, made through the same call in the same run: without it
    // this test passes just as happily against a table that refuses every
    // insert, or against one nobody can write to at all.
    $second = Supporter::factory()->create(['email' => 'someone.else@example.test']);

    expect(Supporter::query()->count())->toBe(2)
        ->and($second->exists)->toBeTrue();

    // And the address is stored exactly as it arrived. Only the *match* is
    // normalized -- the same asymmetry the postcode and the name parts follow,
    // since a folded value is recoverable from the raw one and never the
    // reverse.
    expect(DB::connection('tenant')->table('supporters')->where('id', $first->getKey())->value('email'))
        ->toBe('Jean.Sacren@Example.test');

    // The configuration invariant behind the behaviour. The refusal above is a
    // fact about one pair of rows and could drift onto some other constraint;
    // this names the index doing the work, and would go red if the raw
    // statement that creates it ever ran somewhere other than the campaign's
    // own database -- which is exactly what a connection mistake there would
    // look like, since the table would still be created correctly.
    expect(collect(Schema::getIndexes('supporters'))->pluck('name'))
        ->toContain('supporters_email_unique');
});

test('the column defaults to a contactable supporter for a row that names no status', function (): void {
    // Written straight to the table, bypassing Eloquent and the factory, so the
    // value under test can only have come from the database.
    //
    // This is also what keeps the migration frozen. It hardcodes 'subscribed'
    // rather than reading SubscriptionStatus::default(), because it re-runs for
    // every campaign at whatever date that campaign is provisioned, and a
    // default read out of application code would give campaigns created after
    // an edit a different schema from the ones already provisioned. The cost of
    // hardcoding is that two places must agree; this is what enforces it.
    DB::connection('tenant')->table('supporters')->insert([
        'email' => 'raw-insert@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stored = DB::connection('tenant')->table('supporters')
        ->where('email', 'raw-insert@example.test')
        ->value('subscription_status');

    // Pinned to each other, so the migration's literal cannot drift from the
    // enum in either direction...
    expect($stored)->toBe(SubscriptionStatus::default()->value);

    // ...and pinned to the intended choice, so the pair cannot move together
    // and stay green. Importing a list is not consent the application can
    // verify; it takes the operator's assertion, and a default of Unsubscribed
    // would make every import useless.
    expect(SubscriptionStatus::default())->toBe(SubscriptionStatus::Subscribed);
});

test('the subscription status round-trips through the database as an enum', function (): void {
    $supporter = Supporter::factory()->unsubscribed()->create();

    $reloaded = Supporter::query()->whereKey($supporter->getKey())->sole();

    expect($reloaded->subscription_status)->toBeInstanceOf(SubscriptionStatus::class)
        ->and($reloaded->subscription_status)->toBe(SubscriptionStatus::Unsubscribed);

    // And the stored representation is the enum's backing value rather than a
    // serialization of the case object -- what any later reader, data migration
    // or hand-written query would have to match on.
    expect(DB::connection('tenant')->table('supporters')->where('id', $supporter->getKey())->value('subscription_status'))
        ->toBe('unsubscribed');
});
