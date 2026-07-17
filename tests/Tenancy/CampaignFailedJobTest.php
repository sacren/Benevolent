<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FailInsideCampaign;
use Tests\Support\QueueWorker;

/**
 * Where a campaign's failed work is written down, and where it goes back to.
 *
 * A campaign's data lives in its own database, but the record of a job that
 * failed while doing that campaign's work does not: `failed_jobs` is central,
 * like the queue itself. That is the right home — a failure is platform
 * housekeeping, and a worker reading it is not in any campaign's context — but
 * it means the row has no campaign column, and the only thing tying a failure
 * to the campaign it belongs to is the `tenant_id` the queue bootstrapper
 * stamped into the payload.
 *
 * That stamp is load-bearing twice over: it is the answer to "whose work is
 * failing", and it is what sends a retried job back into its own campaign
 * rather than running it against central or against whichever campaign the
 * worker served last.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    FailInsideCampaign::forget();

    // The suite runs with QUEUE_CONNECTION=sync, which executes a job inline and
    // never fails one through the queue at all.
    config(['queue.default' => 'database']);

    // The `failed_jobs` row is written by this listener, which the `queue:work`
    // command registers as it starts — not by the worker underneath it. So the
    // whole of this file's subject exists only when the command is what is
    // running, and a test driving the worker directly has to register the same
    // listener or watch every failure vanish without trace.
    //
    // Worth knowing beyond this file, because it is a property of the deployed
    // process rather than of the framework: a deployment that runs queued work
    // by any other means records no failures anywhere.
    Event::listen(JobFailed::class, function (JobFailed $event): void {
        app('queue.failer')->log(
            $event->connectionName,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception
        );
    });
});

afterEach(function (): void {
    tenancy()->end();

    FailInsideCampaign::forget();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a job that fails inside a campaign is recorded centrally, and says which campaign', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The second campaign, deliberately: failing the first campaign's job proves
    // nothing about a stamp, because "the first" and "the only" would agree.
    tenancy()->initialize($ridge);

    // A campaign database carries the operator tables and nothing else, so there
    // is nowhere here for a failure to be written even if something tried.
    expect(Schema::connection('tenant')->hasTable('failed_jobs'))->toBeFalse();

    FailInsideCampaign::dispatch();

    tenancy()->end();

    QueueWorker::runNextJob();

    $central = (string) config('tenancy.database.central_connection');
    $failed = DB::connection($central)->table('failed_jobs')->get();

    expect($failed)->toHaveCount(1);

    $payload = json_decode((string) $failed->first()->payload, true);

    expect($payload)->toBeArray()
        // The whole of the campaign's identity in the failure record.
        ->and($payload['tenant_id'] ?? null)->toBe((string) $ridge->getTenantKey())
        // Paired with the run itself, so this is a claim about work that
        // actually happened in that campaign rather than about a payload key.
        ->and(FailInsideCampaign::$attempts)->toBe([(string) $ridge->getTenantKey()]);

    // The job was given up on rather than released for another pass, which is
    // what makes the single recorded attempt above the whole story.
    expect(DB::connection($central)->table('jobs')->count())->toBe(0);
});

test('a retried failure goes back to the campaign it failed in', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    tenancy()->initialize($ridge);
    FailInsideCampaign::dispatch();
    tenancy()->end();

    QueueWorker::runNextJob();

    $central = (string) config('tenancy.database.central_connection');
    $uuid = DB::connection($central)->table('failed_jobs')->value('uuid');

    // Retried from central, which is where an operator retries from: there is no
    // campaign context in a console session, and asking for one would mean
    // knowing which campaign the failure belonged to before reading it.
    expect(tenancy()->initialized)->toBeFalse();

    Artisan::call('queue:retry', ['id' => [$uuid]]);

    // Ended explicitly because `queue:retry` leaves the process inside the
    // campaign it just re-queued for — the queue bootstrapper listens for the
    // retry request and enters the campaign, and nothing puts it back. Harmless
    // in a command that then exits; not something to inherit here, since the
    // worker below must start from central the way a separate process does.
    tenancy()->end();

    QueueWorker::runNextJob();

    $ridgeKey = (string) $ridge->getTenantKey();

    // Two attempts, both in Ridge Restoration. The second is the claim: a
    // payload re-queued from central still carried its campaign, and the worker
    // entered that campaign rather than running the work centrally or in Harbor
    // Cleanup, which is the campaign a process that simply kept going would have
    // been in.
    expect(FailInsideCampaign::$attempts)->toBe([$ridgeKey, $ridgeKey]);

    // And it failed again, which is the honest end of a job that always throws —
    // asserted so that "it ran in the right campaign" cannot be satisfied by a
    // retry that quietly did nothing.
    expect(DB::connection($central)->table('failed_jobs')->count())->toBe(1)
        ->and(DB::connection($central)->table('jobs')->count())->toBe(0);
});

test('the failed-job and batch tables name the central connection instead of following the default', function (): void {
    // The configuration invariant behind the tests above (L-14), and the same
    // shape as the pins on the session store and the queue itself. Left null,
    // these keys mean "whatever connection is currently default", which under
    // tenancy is a campaign's own database rather than the one carrying the
    // table.
    //
    // Where that surfaces is not where it looks like it should, and it was
    // measured rather than reasoned about. Writing the failure survives an
    // unpinned key by luck: the queue bootstrapper reverts tenancy on JobFailed
    // before the listener above logs, so the failer resolves the default while
    // central. The break lands on the *retry* — `queue:retry` enters the
    // campaign to re-queue the job and then deletes the failed row, which dies
    // with `relation "failed_jobs" does not exist` on the campaign's connection.
    // So the behavioural half of this pair is a test away from where the
    // configuration says the fault is, which is the whole argument for asserting
    // the configuration too.
    //
    // Neither key is ours: both arrive from the framework's own config already
    // naming the central connection. That is exactly why they are worth pinning
    // here — nothing else in this application would notice if a scaffold update
    // or an environment change made them follow the default instead.
    $central = config('tenancy.database.central_connection');

    expect(config('queue.failed.database'))->toBe($central)
        ->and(config('queue.batching.database'))->toBe($central);
});
