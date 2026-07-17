<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Tests\Support\QueueWorker;
use Tests\Support\RecordCampaignContext;
use Tests\Support\Url;

/**
 * A queue worker is the canonical multi-campaign process: one long-lived
 * process taking successive jobs that belong to different campaigns, with
 * nothing between them but the queue's own events.
 *
 * That makes it the place where every per-campaign guarantee this application
 * has built is either true or reports success while being wrong. Each of those
 * guarantees is already tested by switching campaigns directly — the database
 * connection, the sender name a campaign signs its mail with, the hostname its
 * links carry, the relying party its passkeys are bound to, the database its
 * password broker writes reset tokens into. None of those tests goes anywhere
 * near a worker, and under php-fpm none of them could see a defect of this
 * family anyway, because a request is its own process and resolves everything
 * inside the campaign it has already switched to.
 *
 * So the claim here is deliberately not "campaign context reaches a job" — that
 * is proven in CampaignJobContextTest. It is that a process serving one
 * campaign after another gives each job the whole of its own campaign, and
 * hands central work back to central.
 *
 * Two campaigns, never one: with a single campaign, "the first" and "the only"
 * are indistinguishable, and every ordering defect of this family passes.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    RecordCampaignContext::forget();

    // The suite runs with QUEUE_CONNECTION=sync, which executes a job inline and
    // raises none of the events the campaign switching is built on. The database
    // driver is what runs in development and production, and it is the only one
    // under which this file tests anything at all.
    config(['queue.default' => 'database']);
});

afterEach(function (): void {
    tenancy()->end();

    RecordCampaignContext::forget();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a worker taking successive jobs answers as each job\'s own campaign', function (): void {
    // Captured before any campaign is entered, so the central expectations below
    // are the application's own values rather than literals restated here.
    $platformSender = config('mail.from.name');
    $platformHost = Url::host((string) config('app.url'));
    $platformRelyingParty = config('passkeys.relying_party_id');
    $centralDatabase = (string) config(
        'database.connections.'.config('tenancy.database.central_connection').'.database'
    );

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    tenancy()->initialize($harbor);
    RecordCampaignContext::dispatch('harbor');

    tenancy()->initialize($ridge);
    RecordCampaignContext::dispatch('ridge');

    tenancy()->end();
    RecordCampaignContext::dispatch('central');

    // The jobs are queued, not run. Leaving campaign context here is what makes
    // this a test of what the worker restores rather than of a connection that
    // happened to still be open: the worker starts from central, exactly as a
    // separate worker process would.
    expect(tenancy()->initialized)->toBeFalse();

    QueueWorker::runNextJobs(3);

    $seen = RecordCampaignContext::$observations;

    // Every job ran, in the order it was queued. Without this, an assertion
    // about a campaign that was never served would be a lookup on a missing key
    // rather than a claim about anything.
    expect(array_keys($seen))->toBe(['harbor', 'ridge', 'central']);

    expect($seen['harbor']['campaign'])->toBe((string) $harbor->getTenantKey())
        ->and($seen['harbor']['database'])->toBe($harbor->database()->getName())
        ->and($seen['harbor']['broker_database'])->toBe($harbor->database()->getName())
        ->and($seen['harbor']['sender'])->toBe('Harbor Cleanup')
        ->and(Url::host($seen['harbor']['url']))->toBe('harbor-cleanup.test')
        ->and($seen['harbor']['relying_party'])->toBe('harbor-cleanup.test');

    // The second campaign is the whole point of the file. Everything above would
    // be equally true of a process that answers as Harbor Cleanup forever.
    expect($seen['ridge']['campaign'])->toBe((string) $ridge->getTenantKey())
        ->and($seen['ridge']['database'])->toBe($ridge->database()->getName())
        ->and($seen['ridge']['broker_database'])->toBe($ridge->database()->getName())
        ->and($seen['ridge']['sender'])->toBe('Ridge Restoration')
        ->and(Url::host($seen['ridge']['url']))->toBe('ridge-restoration.test')
        ->and($seen['ridge']['relying_party'])->toBe('ridge-restoration.test');

    // Stated as one assertion per value, so a later edit cannot quietly drop the
    // half that carries the claim: the two campaigns disagreeing about every one
    // of these is what "each job got its own campaign" means.
    expect($seen['ridge']['database'])->not->toBe($seen['harbor']['database'])
        ->and($seen['ridge']['broker_database'])->not->toBe($seen['harbor']['broker_database'])
        ->and($seen['ridge']['sender'])->not->toBe($seen['harbor']['sender'])
        ->and($seen['ridge']['url'])->not->toBe($seen['harbor']['url'])
        ->and($seen['ridge']['relying_party'])->not->toBe($seen['harbor']['relying_party']);

    // And the direction that only a worker can get wrong: central work following
    // whichever campaign the process happened to serve last.
    expect($seen['central']['campaign'])->toBeNull()
        ->and($seen['central']['database'])->toBe($centralDatabase)
        ->and($seen['central']['broker_database'])->toBe($centralDatabase)
        ->and($seen['central']['sender'])->toBe($platformSender)
        ->and(Url::host($seen['central']['url']))->toBe($platformHost)
        ->and($seen['central']['relying_party'])->toBe($platformRelyingParty);

    // Nothing was left behind for whatever the process does next (L-13).
    expect(tenancy()->initialized)->toBeFalse();
});

test('a job whose campaign no longer exists does not run at all', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Closed Chapter', 'domain' => 'closed-chapter.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $closed = Tenant::query()->where('slug', 'closed-chapter')->firstOrFail();

    tenancy()->initialize($closed);
    RecordCampaignContext::dispatch('closed');

    tenancy()->initialize($harbor);
    RecordCampaignContext::dispatch('harbor');

    tenancy()->end();

    // Deleting a campaign drops its database, so the queued job now names a
    // campaign the worker cannot enter. The question is what it does instead,
    // and the only unacceptable answer is running the job anyway — against
    // central, or against whichever campaign the worker served last.
    $closed->delete();

    QueueWorker::runNextJobs(2);

    // Paired in the same run, because "it did not run" is satisfied perfectly by
    // a worker that runs nothing at all: the surviving campaign's job going
    // through in the same two passes is what makes the absence mean something.
    expect(RecordCampaignContext::$observations)->toHaveKey('harbor')
        ->and(RecordCampaignContext::$observations)->not->toHaveKey('closed');

    $central = (string) config('tenancy.database.central_connection');

    // Both jobs were taken and finished with — the unrunnable one was given up
    // on rather than left in the queue or released for another pass.
    expect(DB::connection($central)->table('jobs')->count())->toBe(0);
});

test('the queue bootstrapper is registered, so a job carries its campaign at all', function (): void {
    // The configuration invariant behind both tests above (L-14). Each of them
    // exercises behaviour through the container, and behaviour can look right by
    // accident of ordering; this cannot. Dropping the class from
    // config/tenancy.php turns this red on its own, and turns both of them red
    // for their own stated reasons — measured, not assumed.
    //
    // It is this bootstrapper that stamps the campaign into every payload as it
    // is queued and re-initializes that campaign before the job runs. Without
    // it, a job dispatched inside a campaign carries nothing, and a worker runs
    // it against whatever context it happens to be in.
    expect(config('tenancy.bootstrappers'))->toContain(QueueTenancyBootstrapper::class);
});
