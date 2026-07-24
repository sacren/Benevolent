<?php

declare(strict_types=1);

use App\Models\SupporterImport;
use App\Models\Tenant;
use App\Supporters\SupporterFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * The retention asked the two questions the Campaign suite cannot ask it.
 *
 * tests/Campaign/SupporterImportFileRetentionTest.php proves what the prune
 * does to one campaign's uploads, inside a campaign the test had already
 * entered. Two things are invisible from there.
 *
 * **Whether it reaches every campaign, and only its own.** The records live in
 * the tenant migration set and the uploads live in each campaign's own storage
 * tree, so this is a per-campaign command that has to be iterated to reach
 * anybody. **Two campaigns, never one:** with a single campaign, a command that
 * cleaned the first campaign repeatedly and never touched the rest reports
 * success and is indistinguishable from a working one — which is exactly what
 * Phase 0 Step 11 measured happening to the framework's own `auth:clear-resets`
 * through a cached password broker (L-21). That defect is in the family this
 * project has now met four times, and it has never once been visible to a
 * single-campaign probe.
 *
 * **Whether it refuses centrally rather than doing something.** Central has no
 * `supporter_imports` table and no campaign storage tree, so there is no smaller
 * central version of this job — it is a different question with no answer. Step
 * 11 found the framework's own command dying on a missing relation in this
 * position, which is a worse thing to read at 3am than a sentence.
 *
 * Provisions its own campaigns rather than using the campaign harness, for
 * L-10's reason: that trait holds one campaign per file inside a transaction
 * that switching to a second campaign would purge.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Put one aged upload and one fresh upload into a campaign.
 *
 * Named for this file rather than shared, because Pest compiles every test file
 * into one process and two global functions sharing a name is a fatal
 * redeclaration that aborts the whole run rather than failing a test.
 *
 * The relative paths are pinned and identical in both campaigns on purpose: the
 * filesystem bootstrapper roots the `local` disk inside each campaign's own
 * tree, so one path names two different files, which is what makes a prune that
 * reached across campaigns visible rather than merely wrong.
 *
 * @return array{0: SupporterImport, 1: SupporterImport}
 */
function stageAgedAndFreshUploads(Tenant $campaign, string $who): array
{
    tenancy()->initialize($campaign);

    Storage::disk(SupporterFile::DISK)->put('imports/aged.csv', "Email\naged-{$who}@example.test\n");
    Storage::disk(SupporterFile::DISK)->put('imports/fresh.csv', "Email\nfresh-{$who}@example.test\n");

    $aged = SupporterImport::query()->forceCreate([
        'original_filename' => 'aged.csv',
        'stored_path' => 'imports/aged.csv',
        'headers' => ['Email'],
        'created_at' => now()->subDays(8),
    ]);

    $fresh = SupporterImport::query()->forceCreate([
        'original_filename' => 'fresh.csv',
        'stored_path' => 'imports/fresh.csv',
        'headers' => ['Email'],
        'created_at' => now()->subDay(),
    ]);

    tenancy()->end();

    return [$aged, $fresh];
}

test('the prune reaches every campaign and takes only that campaign\'s uploads', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    stageAgedAndFreshUploads($harbor, 'harbor');
    stageAgedAndFreshUploads($ridge, 'ridge');

    // The premise the whole file rests on, asserted rather than assumed: one
    // relative path really does name two different files. Were the disks ever to
    // stop being re-rooted per campaign, these tests would keep passing while
    // testing nothing.
    tenancy()->initialize($harbor);
    $harborAged = Storage::disk(SupporterFile::DISK)->get('imports/aged.csv');
    tenancy()->initialize($ridge);
    $ridgeAged = Storage::disk(SupporterFile::DISK)->get('imports/aged.csv');
    tenancy()->end();

    expect($harborAged)->not->toBe($ridgeAged);

    Artisan::call('tenants:run', ['commandname' => 'supporters:prune-import-files']);

    // Both directions in both campaigns. The "still there" halves are what make
    // the removals evidence: a command that deleted every file it could find
    // satisfies the negatives on its own, and one that never ran at all
    // satisfies the positives.
    foreach ([$harbor, $ridge] as $campaign) {
        tenancy()->initialize($campaign);

        expect(Storage::disk(SupporterFile::DISK)->exists('imports/aged.csv'))->toBeFalse()
            ->and(Storage::disk(SupporterFile::DISK)->exists('imports/fresh.csv'))->toBeTrue();

        $imports = SupporterImport::query()->orderBy('id')->get();

        // The record survives in both cases; only the aged one stops naming a
        // file. Two campaigns, and the second is the one a cached connection or
        // a captured campaign would have skipped while reporting success.
        expect($imports)->toHaveCount(2)
            ->and($imports[0]->stored_path)->toBeNull()
            ->and($imports[0]->original_filename)->toBe('aged.csv')
            ->and($imports[1]->stored_path)->toBe('imports/fresh.csv');

        tenancy()->end();
    }
});

test('the prune refuses to run centrally rather than finding nothing', function (): void {
    // Central holds no supporter_imports table and no campaign storage tree, so
    // "prune nothing" and "prune the wrong thing" are both worse answers than
    // declining. The failure status is asserted alongside the message, because a
    // command that printed advice and returned success would still be reported
    // as a clean run by any scheduler.
    expect(tenancy()->initialized)->toBeFalse();

    $status = Artisan::call('supporters:prune-import-files');

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain('tenants:run');
});

test('the prune is scheduled to reach every campaign daily', function (): void {
    // The configuration invariant behind the two tests above (L-14), and it was
    // **missing when this step first wrote them**: dropping `tenants:run` from
    // routes/console.php reddened nothing at all across the whole suite, which
    // is the same guard-that-cannot-fail shape the reset-token cleanup and the
    // queue prunes each carry a test for. Found by breaking the schedule rather
    // than by reading it.
    //
    // What the tests above prove is that the command does the right thing when
    // something invokes it. Nothing in them proves anything ever invokes it, and
    // "simplifying" the declaration to the bare command would leave a scheduled
    // task that -- per the test above -- refuses on every run, on a server where
    // nobody reads the output.
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'supporters:prune-import-files'));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled->sole())->toContain('tenants:run')
        // No --option, deliberately: the window is the command's own default
        // because it is our command, unlike the queue prunes which must override
        // the framework's. A --option appearing here means the two have drifted.
        ->and($scheduled->sole())->not->toContain('--option');

    $event = collect(app(Schedule::class)->events())
        ->sole(fn ($event): bool => str_contains((string) $event->command, 'supporters:prune-import-files'));

    expect($event->expression)->toBe('0 0 * * *');
});
