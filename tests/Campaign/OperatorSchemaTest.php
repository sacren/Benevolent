<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the campaign database carries the operator tables', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeTrue()
        ->and(Schema::hasTable('passkeys'))->toBeTrue()
        ->and(Schema::hasColumns('users', [
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ]))->toBeTrue();
});

test('the campaign database does not carry the central sessions table', function (): void {
    // Sessions are shared web infrastructure rather than operator identity, so
    // the scaffold's combined migration was divided rather than moved. A
    // campaign database owning `sessions` would mean the split went too far.
    expect(Schema::hasTable('sessions'))->toBeFalse();
});

test('an operator created in campaign context lands in the campaign database, not the central one', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName());

    $this->assertDatabaseHas('users', ['email' => 'operator@example.test'], 'tenant');

    // The central database still has a `users` table at this point, so this is a
    // real check that the write followed the connection into the campaign rather
    // than a table-missing error dressed up as a pass.
    $this->assertDatabaseMissing(
        'users',
        ['email' => 'operator@example.test'],
        (string) config('tenancy.database.central_connection'),
    );
});

test('a test sees an empty operator table even though the previous test filled it', function (): void {
    // Paired with the test below: the campaign database is provisioned once for
    // the whole file, so isolation between tests comes from the transaction the
    // harness opens and rolls back, not from re-provisioning.
    expect(User::query()->count())->toBe(0);

    User::factory()->count(3)->create();

    expect(User::query()->count())->toBe(3);
});

test('the operators from the previous test are gone', function (): void {
    expect(User::query()->count())->toBe(0);
});

test('a request to the campaign host keeps the test transaction alive', function (): void {
    // What every relocated auth test depends on: resolving a campaign from the
    // Host header re-enters tenancy initialization, which must return early for
    // the already-active campaign instead of reconnecting. A reconnect would
    // silently discard the transaction, and with it any operator the test
    // arranged before making the request.
    $operator = User::factory()->create();

    $this->get($this->campaignUrl('/login'))->assertOk();

    expect(User::query()->whereKey($operator->getKey())->exists())->toBeTrue();
});
