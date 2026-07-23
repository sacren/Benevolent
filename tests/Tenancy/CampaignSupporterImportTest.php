<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\SupporterImport;
use App\Models\Tenant;
use App\Supporters\ImportStatus;
use App\Supporters\ImportSupporters;
use App\Supporters\SupporterFile;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QueueWorker;
use Tests\Support\StagedImport;

/**
 * The application's first queued job, asked the questions only a worker can
 * answer.
 *
 * tests/Campaign/SupporterImportTest.php exercises the reading and the writing,
 * and it does so inside a campaign the test had already entered — under
 * QUEUE_CONNECTION=sync, which runs a job inline and raises none of the events
 * the campaign switching is built on. So it proves what an import *does* and
 * nothing at all about where it does it.
 *
 * This file is where that is settled, through a real worker taking successive
 * jobs in one process, which is the mechanism that runs in production and the
 * only one under which a campaign can be got wrong. **Two campaigns, never
 * one:** with a single campaign, "the first" and "the only" are
 * indistinguishable and every ordering defect of this family passes.
 *
 * It is also this step's evidence for D-6. A tenant-aware base job class earns
 * its place only if it carries something QueueTenancyBootstrapper does not, and
 * what is asserted below — the campaign's own database, its own uploaded file,
 * and its own record written from the failure hook — is the whole of what such
 * a class could have carried. The job extends nothing.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    config(['queue.default' => 'database']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a worker taking two campaigns\' imports gives each its own list and its own file', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // Both files are written at the same relative path on the same disk, which
    // is the point: the filesystem bootstrapper roots that disk inside each
    // campaign's own tree, so one path names two different files. If it did not,
    // the second import would read the first campaign's people.
    tenancy()->initialize($harbor);
    $harborImport = StagedImport::of("Email\nharbor.supporter@example.test\n",
        StagedImport::addressOnlyMapping(), path: 'imports/list.csv');
    ImportSupporters::dispatch($harborImport);

    tenancy()->initialize($ridge);
    $ridgeImport = StagedImport::of("Email\nridge.supporter@example.test\n",
        StagedImport::addressOnlyMapping(), path: 'imports/list.csv');
    ImportSupporters::dispatch($ridgeImport);

    // The jobs are queued, not run. Leaving campaign context is what makes this
    // a test of what the worker restores rather than of a connection that
    // happened to still be open — a separate worker process starts from central.
    tenancy()->end();
    expect(tenancy()->initialized)->toBeFalse();

    QueueWorker::runNextJobs(2);

    expect(tenancy()->initialized)->toBeFalse();

    tenancy()->initialize($harbor);
    expect(Supporter::query()->pluck('email')->all())->toBe(['harbor.supporter@example.test']);
    expect($harborImport->fresh())->status->toBe(ImportStatus::Completed)->supporters_added->toBe(1);

    tenancy()->initialize($ridge);
    expect(Supporter::query()->pluck('email')->all())->toBe(['ridge.supporter@example.test']);
    expect($ridgeImport->fresh())->status->toBe(ImportStatus::Completed)->supporters_added->toBe(1);
});

test('the queued payload carries the import\'s identity, never anybody\'s personal data', function (): void {
    // Deferral 24's other half, and the reason this job is constructed with a
    // record rather than with rows. Every failure copies the payload into
    // central `failed_jobs` — outside the campaign, and not on Step 11's
    // personal-data inventory — so a job carrying parsed rows would spill a
    // campaign's supporters into a central table every time one failed.
    //
    // Measured, not assumed: a model property is serialized as a
    // ModelIdentifier (class, id, connection), while a plain string property is
    // written into the payload verbatim.
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    tenancy()->initialize($harbor);
    ImportSupporters::dispatch(StagedImport::of("Email\nprivate.person@example.test\n", StagedImport::addressOnlyMapping()));
    tenancy()->end();

    $payload = (string) DB::connection(config('tenancy.database.central_connection'))
        ->table('jobs')
        ->value('payload');

    expect($payload)->not->toContain('private.person@example.test')
        ->and($payload)->not->toContain('supporters.csv')
        // What it does carry: the campaign, so a failure can be attributed and a
        // retry can go home, and the record's class, so the job can find it.
        ->and(json_decode($payload, true)['tenant_id'] ?? null)->toBe((string) $harbor->getTenantKey())
        ->and($payload)->toContain('App\\\\Models\\\\SupporterImport');
});

test('an import that fails writes why onto its own campaign\'s record', function (): void {
    // What an operator sees after a failure, and it can only be here: the
    // central `failed_jobs` row has no campaign column and no campaign surface
    // reads it, so a failure recorded only there leaves the person who uploaded
    // the file with nothing. The job's failed() hook still runs in campaign
    // context — which is what this asserts — where the JobFailed listener that
    // writes the central row runs after tenancy has already reverted.
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    Event::listen(JobFailed::class, function (JobFailed $event): void {
        // Registered by the `queue:work` command as it starts, not by the worker
        // underneath it, so a test driving the worker has to register it or
        // watch every failure vanish without trace.
        app('queue.failer')->log(
            $event->connectionName,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception
        );
    });

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The second campaign, deliberately: failing the first campaign's import
    // would prove nothing, because "the first" and "the only" agree.
    tenancy()->initialize($ridge);

    $import = StagedImport::of("Email\nsomeone@example.test\n", StagedImport::addressOnlyMapping());

    // The record says a file is there and the disk no longer agrees.
    Storage::disk(SupporterFile::DISK)->delete($import->stored_path);

    ImportSupporters::dispatch($import);

    tenancy()->end();

    QueueWorker::runNextJob();

    tenancy()->initialize($ridge);

    expect($import->fresh())
        ->status->toBe(ImportStatus::Failed)
        ->and($import->fresh()->failure_reason)->toContain('could not be read')
        ->and($import->fresh()->finished_at)->not->toBeNull();

    // The other campaign has no record of it at all, which is what makes the
    // line above a claim about *this* campaign rather than about a write that
    // happened to land somewhere.
    tenancy()->initialize($harbor);
    expect(SupporterImport::query()->count())->toBe(0);

    // And the central row still names the campaign, so the platform can answer
    // whose work is failing even though the campaign answers why.
    tenancy()->end();
    $failed = DB::connection(config('tenancy.database.central_connection'))->table('failed_jobs')->get();

    expect($failed)->toHaveCount(1)
        ->and(json_decode((string) $failed->first()->payload, true)['tenant_id'] ?? null)
        ->toBe((string) $ridge->getTenantKey());
});
