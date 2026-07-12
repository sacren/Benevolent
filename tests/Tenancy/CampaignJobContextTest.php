<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnrolOperator;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Processes exactly one queued job, the way a worker would.
 *
 * Deliberately not `queue:work`: the worker runs here, in this process, for one
 * job, against the testing database. Running the real command would put a
 * long-lived process outside the test's control, and dispatching into the
 * developer's own queue is not this suite's business.
 *
 * It has to be the worker rather than calling the job's handle() directly,
 * because the campaign context is restored by a listener on the JobProcessing
 * event — which only the worker fires. Calling handle() would prove nothing
 * about propagation.
 */
function processOneQueuedJob(): void
{
    // Resolved by its container alias rather than its class name: the worker
    // takes a maintenance-mode callable the container cannot autowire, and the
    // alias is the binding `queue:work` itself uses.
    $worker = app('queue.worker');

    expect($worker)->toBeInstanceOf(Worker::class);

    // maxTries is set explicitly because WorkerOptions defaults it to 0, which
    // means "retry forever". One try mirrors `queue:work --tries=1` and is the
    // only setting under which the failed_jobs assertion below can mean
    // anything. The decisive assertion in this file is the operator row and the
    // campaign id it carries, not the queue counts.
    $worker->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));
}

test('a job dispatched in campaign context runs against that campaign database', function (): void {
    Artisan::call('campaign:create', ['name' => 'Doorknock Drive', 'domain' => 'doorknock-drive.test']);
    Artisan::call('campaign:create', ['name' => 'Phonebank Push', 'domain' => 'phonebank-push.test']);

    config(['queue.default' => 'database']);

    $campaign = Tenant::query()->where('slug', 'doorknock-drive')->firstOrFail();
    $other = Tenant::query()->where('slug', 'phonebank-push')->firstOrFail();

    tenancy()->initialize($campaign);

    EnrolOperator::dispatch('volunteer@doorknock-drive.test');

    // The job is queued, not run. Ending tenancy here is what makes this a real
    // test of propagation rather than of a connection that happened to still be
    // open: the worker below starts from central context, exactly as a separate
    // worker process would.
    tenancy()->end();

    expect(tenancy()->initialized)->toBeFalse();

    processOneQueuedJob();

    $central = (string) config('tenancy.database.central_connection');

    // A job the worker could not run is recorded rather than thrown, so assert
    // the queue is genuinely empty rather than merely unprocessed.
    expect(DB::connection($central)->table('failed_jobs')->count())->toBe(0)
        ->and(DB::connection($central)->table('jobs')->count())->toBe(0);

    // The worker put central context back when the job finished, so nothing
    // after it silently inherits the campaign (see L-13).
    expect(tenancy()->initialized)->toBeFalse();

    tenancy()->initialize($campaign);

    $operator = User::query()->where('email', 'volunteer@doorknock-drive.test')->first();

    expect($operator)->not->toBeNull()
        // The campaign the job saw while running, not merely the database it
        // landed in — a right row under a wrong id would mean the connection
        // was restored without the context.
        ->and($operator->name)->toBe((string) $campaign->getTenantKey());

    tenancy()->end();
    tenancy()->initialize($other);

    expect(User::query()->where('email', 'volunteer@doorknock-drive.test')->exists())->toBeFalse();
});

test('a job dispatched centrally carries no campaign context', function (): void {
    config(['queue.default' => 'database']);

    expect(tenancy()->initialized)->toBeFalse();

    dispatch(function (): void {
        // Never run; this test reads the payload rather than processing it.
    });

    $central = (string) config('tenancy.database.central_connection');
    $payload = json_decode((string) DB::connection($central)->table('jobs')->value('payload'), true);

    // Central work must stay central. The stamp is what a worker reads to decide
    // whether to enter a campaign, so its absence is what keeps a central job
    // from being run inside whichever campaign the worker last served.
    expect($payload)->toBeArray()
        ->and($payload)->not->toHaveKey('tenant_id');
});
