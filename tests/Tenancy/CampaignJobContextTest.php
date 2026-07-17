<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnrolOperator;
use Tests\Support\QueueWorker;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

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

    QueueWorker::runNextJob();

    $central = (string) config('tenancy.database.central_connection');

    // The queue is genuinely empty rather than merely unprocessed: a job the
    // worker gave up on is deleted from `jobs` as well, so an empty table here
    // says the job was taken and finished with rather than left behind or
    // released for another pass.
    //
    // There was a second assertion here, that `failed_jobs` was empty, and it
    // could not have failed for the reason it named. Nothing in this process
    // writes that table: the row is inserted by a JobFailed listener that the
    // `queue:work` command registers as it starts, not by the worker underneath
    // it — so under runNextJob() a job that throws leaves no trace at all, and
    // an empty failed_jobs table was true of every possible outcome.
    // CampaignFailedJobTest registers that listener and is where a failure is
    // actually asserted.
    expect(DB::connection($central)->table('jobs')->count())->toBe(0);

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
