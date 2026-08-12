<?php

declare(strict_types=1);

use App\Districts\ZctaDistricts;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Every campaign reads the same district data, and reading it reaches no
 * database at all.
 *
 * This is DEC-1's isolation question asked of the first data this platform holds
 * that belongs to no campaign. Congressional boundaries are public and identical
 * for everyone, so the property to prove is the opposite of every isolation test
 * beside this one: two campaigns must get the *same* answer. And because the data
 * ships with the code rather than living in a database (D-34), the way to prove
 * that no campaign's supporters can reach another through it is that reading it
 * issues no query of any kind -- there is nothing for it to join, cache or leak.
 *
 * **Two campaigns, never one (L-21)**, because the failure this project keeps
 * finding is a value captured in one campaign and served to the next.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('two campaigns and the platform read the same districts, and none of them issues a query to do it', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration']);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $read = function () use (&$queries): array {
        $queries = 0;
        $districts = ZctaDistricts::shipped();

        return [
            'congress' => $districts->congress(),
            '90210' => $districts->districtsTouching('90210'),
            'queries' => $queries,
        ];
    };

    $expected = ['congress' => 119, '90210' => ['0630', '0632', '0636'], 'queries' => 0];

    // Initializing a campaign can itself query the registry, so each read is
    // counted from the moment the campaign is already active.
    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    $harbor = $read();

    tenancy()->initialize(Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail());
    $ridge = $read();

    tenancy()->end();
    $platform = $read();

    // The real answer, not merely an agreeing one: three readers returning
    // nothing would agree perfectly, so each is held to the known relation.
    expect($harbor)->toBe($expected)
        ->and($ridge)->toBe($expected)
        ->and($platform)->toBe($expected);
});

test('the data is read from a path campaigns do not re-root, as their storage is', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);

    $centralResources = resource_path();
    $centralStorage = storage_path();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());

    // The configuration invariant behind the test above. The filesystem
    // bootstrapper gives each campaign its own storage directory, so a data file
    // kept under storage/ would be looked for in a different place by every
    // campaign and found by none of them. resources/ is not re-rooted -- the
    // storage half of this assertion is what shows the bootstrapper really ran.
    expect(storage_path())->not->toBe($centralStorage)
        ->and(resource_path())->toBe($centralResources)
        ->and(resource_path(ZctaDistricts::fileFor(ZctaDistricts::SHIPPED_CONGRESS)))->toBeFile();
});
