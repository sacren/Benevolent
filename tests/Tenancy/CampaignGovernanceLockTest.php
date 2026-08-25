<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Operators\RemoveOperator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Two governors stepping down at once must not both succeed (§7 criterion 3).
 *
 * CampaignGovernance reads the governors under a row lock, so a second writer
 * asking "does anybody else govern?" waits for the first to commit and then
 * reads the roster it left behind. Without the lock both read the other still
 * in office, both leave, and the campaign has nobody -- the door, reached by
 * timing rather than by a missing check, which no request-at-a-time test can
 * see.
 *
 * **Asked with a second connection holding the lock a concurrent writer
 * would hold**, and a lock timeout on the first, so that "the writer waited"
 * becomes an error this process can observe rather than a hang. It lives in
 * this suite rather than beside OperatorGovernanceTest because the campaign
 * harness wraps each test in a transaction, and rows written inside it are
 * invisible to any other connection -- the rival here would lock nothing.
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());

    config(['database.connections.rival' => config('database.connections.tenant')]);
});

afterEach(function (): void {
    DB::connection('rival')->rollBack();
    DB::purge('rival');

    DB::connection('tenant')->statement('set lock_timeout = 0');

    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a governor leaving waits for another governor\'s change to finish, and then goes', function (): void {
    $leaving = User::factory()->owner()->create(['email' => 'leaving@example.test']);
    $other = User::factory()->owner()->create(['email' => 'other@example.test']);

    // The rival is mid-way through changing the other Owner -- stepping down,
    // say -- and holds that row until it commits.
    DB::connection('rival')->beginTransaction();
    DB::connection('rival')->table('users')->where('id', $other->getKey())->lockForUpdate()->first();

    DB::connection('tenant')->statement("set lock_timeout = '300ms'");

    // **The negative: the departure waits rather than reading a roster that is
    // about to change.** 55P03 is PostgreSQL's lock_not_available, raised only
    // because the writer asked for the lock and was made to wait for it.
    expect(fn () => app(RemoveOperator::class)($leaving, 'refused'))
        ->toThrow(function (QueryException $exception): void {
            expect($exception->getCode())->toBe('55P03');
        });

    expect(User::query()->whereKey($leaving->getKey())->exists())->toBeTrue();

    // **The positive half**: the rival finishes without changing anything,
    // and the same departure goes through, because the other Owner stays.
    DB::connection('rival')->rollBack();

    app(RemoveOperator::class)($leaving, 'refused');

    expect(User::query()->pluck('email')->all())->toBe(['other@example.test']);
});
