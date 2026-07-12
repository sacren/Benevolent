<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a job dispatched in campaign context is queued in the central database', function (): void {
    // The suite runs with QUEUE_CONNECTION=sync, which executes a job inline and
    // never touches a `jobs` table, so this is the one place the real dev and
    // production driver is exercised. Tenancy switches the default connection
    // onto the campaign's database, and `jobs` is central-only — so a queue
    // connection left following the default would try to write the row into the
    // campaign's own database and fail on a missing table, while the rest of the
    // suite stayed green. The same shape as the session store in L-7.
    Artisan::call('campaign:create', ['name' => 'Queue Probe', 'domain' => 'queue-probe.test']);

    config(['queue.default' => 'database']);

    tenancy()->initialize(Tenant::query()->where('slug', 'queue-probe')->firstOrFail());

    // A campaign database carries the operator tables and nothing else. Asserted
    // rather than assumed, because it is the whole reason the row cannot live here.
    expect(Schema::connection('tenant')->hasTable('jobs'))->toBeFalse();

    // The precondition this test turns on, asserted so it cannot be lost to a
    // tidy-up. QueueManager caches a resolved connection for the life of the
    // process, and a DatabaseQueue keeps whichever database connection was
    // default at the moment it was built. Resolving it here — inside campaign
    // context, having never resolved it before — is what a php-fpm request
    // serving a campaign host does. Provision the campaign *after* switching the
    // default to `database` instead, and `campaign:create` resolves the queue
    // centrally first, handing this test a central handle and hiding the very
    // defect it exists to catch.
    expect(Queue::connected('database'))->toBeFalse();

    dispatch(function (): void {
        // Deliberately does nothing. This commit is about where the row is
        // written, not what the job later does with it — that is the next one.
    });

    $central = (string) config('tenancy.database.central_connection');

    expect(DB::connection($central)->table('jobs')->count())->toBe(1);
});

test('the queue names its connection instead of following the default', function (): void {
    // The invariant behind the test above, asserted directly so a regression is
    // reported as the misconfiguration it is rather than as a missing table.
    // Left null, this key means "whatever connection is currently default",
    // which tenancy has already repointed at the campaign by dispatch time.
    expect(config('queue.connections.database.connection'))
        ->toBe(config('tenancy.database.central_connection'));
});
