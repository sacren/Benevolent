<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\StagedImport;

/**
 * What a campaign owns besides its database, and what happens to it when the
 * campaign is deleted.
 *
 * Database-per-tenant makes "a campaign's data" feel like a settled question,
 * and the filesystem bootstrapper quietly adds a second answer to it: each
 * campaign also gets its own storage tree, and once a list can be imported that
 * tree holds the uploaded file -- the same people as the database, in the clear,
 * and not a database at all. Deleting the campaign already deleted the obvious
 * half. This file is the guard on the other one.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php -- CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('deleting a campaign takes its uploaded lists with it', function (): void {
    // The campaign's database is the thing everyone pictures when they think
    // about where a campaign's supporters live, and Stancl's DeleteDatabase
    // already removes it. The uploaded file holds the same people -- names,
    // addresses and postcodes, in the clear -- and is not a database at all, so
    // it is invisible to that instinct. Without the second half of the delete
    // pipeline the campaign's people outlive the campaign indefinitely, in a
    // directory named after something that no longer exists.
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    $directory = fn (Tenant $campaign): string => sprintf(
        '%s/%s%s',
        rtrim(app()->storagePath(), '/'),
        (string) config('tenancy.filesystem.suffix_base'),
        $campaign->getTenantKey(),
    );

    foreach ([$harbor, $ridge] as $campaign) {
        tenancy()->initialize($campaign);
        StagedImport::of("Email\nsomeone@example.test\n", StagedImport::addressOnlyMapping());
    }

    tenancy()->end();

    // The positive half. Without it, the assertions below would pass just as
    // happily against a campaign that never wrote a file at all.
    expect(is_dir($directory($harbor)))->toBeTrue()
        ->and(is_dir($directory($ridge)))->toBeTrue();

    $harbor->delete();

    expect(is_dir($directory($harbor)))->toBeFalse()
        // Two campaigns, never one: deleting the only campaign's directory
        // cannot tell a targeted removal from a sweep of everything.
        ->and(is_dir($directory($ridge)))->toBeTrue();
});
