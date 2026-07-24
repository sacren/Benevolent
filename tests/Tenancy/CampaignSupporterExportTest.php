<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * The export asked the one question the Campaign suite cannot ask it.
 *
 * tests/Campaign/SupporterExportTest.php proves what the file contains, who is
 * refused, and what the browser is told to call it — inside a single campaign
 * the test had already entered. It therefore proves nothing about *which*
 * campaign's list is being handed out, because with one campaign there is no
 * other answer available.
 *
 * **Two campaigns, never one.** This project has met a connection or singleton
 * captured against the first campaign in a process three separate times (L-15,
 * L-21, and the password broker at Phase 0 Step 11), and every one of them was
 * invisible to a single-campaign probe: "the first" and "the only" are the same
 * campaign. Two requests in one process, on two hostnames, is the shape that
 * can see it.
 *
 * **The filename is guarded here and nowhere else, and it is the export's only
 * new read of campaign context.** SupporterExport::filename() asks tenant() for
 * the campaign's slug — the sole place this module reads the campaign record
 * rather than following the switched connection. Everything else in the export
 * inherits its isolation from Supporter naming no connection, which
 * CampaignSupporterIsolationTest already states. A slug read against the wrong
 * campaign would not lose or leak a single row; it would put the other
 * campaign's name on the file, which is the kind of fault nobody reports as a
 * bug and everybody notices in a folder of downloads.
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

test('each campaign exports its own people, under its own name, in one process', function (): void {
    // Named differently from the helper in CampaignSupporterHttpIsolationTest:
    // Pest compiles every test file into one process, so two global functions
    // sharing a name is a fatal redeclaration that aborts the whole run rather
    // than failing a test.
    $enrol = function (string $slug, string $operatorEmail, string $supporterEmail): void {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

        User::factory()->owner()->create(['email' => $operatorEmail]);
        Supporter::factory()->create(['email' => $supporterEmail]);

        tenancy()->end();
    };

    $enrol('harbor-cleanup', 'operator@harbor-cleanup.test', 'signer@harbor-cleanup.test');
    $enrol('ridge-restoration', 'operator@ridge-restoration.test', 'signer@ridge-restoration.test');

    // Signed in for real rather than through actingAs(), the way the sibling
    // HTTP isolation file argues for: binding a User object resolved out of one
    // campaign's database skips the lookup, and the lookup is where a campaign
    // can be got wrong.
    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $harborExport = $this->get('http://harbor-cleanup.test/supporters/export');
    $harborExport->assertOk();

    $harborBody = $harborExport->streamedContent();
    $harborFilename = $harborExport->headers->get('Content-Disposition');

    // The second campaign, in the same process, which is the whole point of the
    // file. Whatever the first request cached is still cached.
    $this->post('http://ridge-restoration.test/login', [
        'email' => 'operator@ridge-restoration.test',
        'password' => 'password',
    ])->assertRedirect();

    $ridgeExport = $this->get('http://ridge-restoration.test/supporters/export');
    $ridgeExport->assertOk();

    $ridgeBody = $ridgeExport->streamedContent();
    $ridgeFilename = $ridgeExport->headers->get('Content-Disposition');

    // Both directions on both campaigns. The positive halves are what make the
    // negatives evidence: an export that returned an empty file for everybody
    // would satisfy every "does not contain" on its own.
    expect($harborBody)->toContain('signer@harbor-cleanup.test')
        ->and($harborBody)->not->toContain('signer@ridge-restoration.test')
        ->and($ridgeBody)->toContain('signer@ridge-restoration.test')
        ->and($ridgeBody)->not->toContain('signer@harbor-cleanup.test');

    // And the filename, the export's own read of the campaign record.
    expect($harborFilename)->toContain('harbor-cleanup-supporters-')
        ->and($harborFilename)->not->toContain('ridge-restoration')
        ->and($ridgeFilename)->toContain('ridge-restoration-supporters-')
        ->and($ridgeFilename)->not->toContain('harbor-cleanup');
});
