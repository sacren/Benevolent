<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('an operator carries a role, and it lives in the campaign database', function (): void {
    // A role says what someone may do inside one campaign, so it belongs in
    // that campaign's database alongside the identity it describes. There is
    // no central users table for a central role to hang on -- CentralSchemaTest
    // asserts that absence -- so this is the whole storage claim.
    expect(Schema::hasColumn('users', 'role'))->toBeTrue();

    $operator = User::factory()->create();

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName())
        ->and($operator->role)->toBe(OperatorRole::default());
});

test('the column defaults to the least privileged role for a row that names none', function (): void {
    // The behavioural assertion above is satisfied by the factory alone, which
    // would leave the schema default unproven and free to drift. This writes
    // straight to the table, bypassing Eloquent, so the value under test can
    // only have come from the database.
    //
    // This is also what keeps the migration frozen. The migration hardcodes
    // 'staff' rather than reading OperatorRole::default(), because it re-runs
    // for every campaign at whatever date that campaign is provisioned, and a
    // default read out of application code would give campaigns created after
    // an edit a different schema from the ones already provisioned. The cost of
    // hardcoding is that two places must agree; this test is what enforces it,
    // failing the moment either side moves alone.
    //
    // The direction matters more than the value: a creator that forgets to set
    // a role must produce the *least* privileged operator. Flipping this
    // default to Owner would hand governance to anything careless.
    DB::connection('tenant')->table('users')->insert([
        'name' => 'Rowena Rowe',
        'email' => 'raw-insert@example.test',
        'password' => 'irrelevant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stored = DB::connection('tenant')->table('users')
        ->where('email', 'raw-insert@example.test')
        ->value('role');

    // Pinned to each other, so the migration's literal cannot drift from the
    // enum in either direction...
    expect($stored)->toBe(OperatorRole::default()->value);

    // ...and pinned to the intended choice, so the pair cannot move together
    // to something more privileged and stay green.
    expect(OperatorRole::default())->toBe(OperatorRole::Staff);
});

test('the role round-trips through the database as an enum rather than a string', function (): void {
    $operator = User::factory()->owner()->create();

    $reloaded = User::query()->whereKey($operator->getKey())->sole();

    expect($reloaded->role)->toBeInstanceOf(OperatorRole::class)
        ->and($reloaded->role)->toBe(OperatorRole::Owner);

    // And the stored representation is the enum's backing value, not a
    // serialization of the case object -- what any later data migration or
    // hand-written query would have to match on.
    expect(DB::connection('tenant')->table('users')->where('id', $operator->getKey())->value('role'))
        ->toBe('owner');
});

test('a role cannot be granted by mass assignment', function (): void {
    // Registration passes request input straight into User::create, so `role`
    // is deliberately absent from the model's fillable list. Were it fillable,
    // anyone reaching a campaign's open /register could post role=owner and
    // grant themselves governance of the campaign.
    $operator = User::create([
        'name' => 'Mallory Vance',
        'email' => 'escalation@example.test',
        'password' => 'password',
        'role' => OperatorRole::Owner->value,
    ]);

    expect($operator->refresh()->role)->toBe(OperatorRole::Staff);
});
