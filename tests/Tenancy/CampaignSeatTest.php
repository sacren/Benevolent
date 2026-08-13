<?php

declare(strict_types=1);

use App\Districts\ZctaDistricts;
use App\Models\Tenant;
use App\Tenancy\CampaignSeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Each campaign's seat is that campaign's own (D-40).
 *
 * The second value stored on a campaign's registry row, after its reply
 * address, and guarded the way that one is:
 * tests/Tenancy/CampaignContactTest.php guards the address, and
 * CampaignSettingsStorageTest the mechanism both sit on.
 *
 * **Two campaigns throughout, never one (L-21).** A seat is read on every
 * request to the supporter list, from the object tenancy is holding; a value
 * captured once and served to the next campaign is the failure this project
 * has measured five times, and with one campaign "the first" and "the only"
 * cannot be told apart.
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

test('each campaign reads its own seat, and still does after another is served', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration']);

    expect(Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']))->toBe(0)
        ->and(Artisan::call('campaign:seat', ['slug' => 'ridge-restoration', 'seat' => 'ca-37']))->toBe(0);

    $relation = ZctaDistricts::shipped();

    // Each campaign read from the registry rather than reused, so nothing here
    // can read back the object it wrote to.
    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    expect(CampaignSeat::current($relation)?->label())->toBe('MA-07');

    tenancy()->initialize(Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail());

    // Stored as it is shown, whatever the operator typed, and disagreeing with
    // the other campaign as one assertion so a later edit cannot drop half.
    expect(CampaignSeat::stored())->toBe('CA-37')
        ->and(CampaignSeat::current($relation)?->label())->toBe('CA-37')
        ->and(CampaignSeat::current($relation)?->label())->not->toBe('MA-07');

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    expect(CampaignSeat::current($relation)?->label())->toBe('MA-07');
});

test('a campaign that records no seat reads none, rather than inheriting one', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration']);
    Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']);

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    $harborReads = CampaignSeat::stored();

    tenancy()->initialize(Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail());

    // The negative carries the positive, made through the same call in the same
    // run (L-19): "Ridge has no seat" is satisfied by storage that never works.
    expect(CampaignSeat::stored())->toBeNull()
        ->and($harborReads)->toBe('MA-07');
});

test('outside a campaign there is no seat', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']);

    expect(CampaignSeat::stored())->toBeNull();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    expect(CampaignSeat::stored())->toBe('MA-07');

    tenancy()->end();
    expect(CampaignSeat::stored())->toBeNull();
});

test('the seat is stored on the registry row and costs no query to read', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']);

    // `seat` is not a custom column, so VirtualColumn folds it into `data` and no
    // migration was owed; and it is not the key CampaignSettingsStorageTest
    // reserves as its own and says nothing in the application reads.
    expect(Tenant::getCustomColumns())->not->toContain(CampaignSeat::KEY)
        ->and(CampaignSeat::KEY)->not->toBe('enabled_modules');

    $relation = ZctaDistricts::shipped();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(CampaignSeat::current($relation)?->label())->toBe('MA-07')
        ->and($queries)->toBe(0);
});

test('campaign:seat refuses a seat that does not exist, and leaves the one a campaign has', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']);

    // California has 52 seats. The direction that matters: a refusal that had
    // already written would report failure while replacing a real seat.
    expect(Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'CA-53']))->toBe(1)
        ->and(Artisan::output())->toContain('"CA-53" is not a seat in the 119th Congress\'s districts');

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    expect(CampaignSeat::stored())->toBe('MA-07');
});

test('campaign:seat shows a campaign\'s seat, and says when it has none', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration']);
    Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']);

    expect(Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup']))->toBe(0)
        ->and(Artisan::output())->toContain('"Harbor Cleanup" is running for MA-07.');

    expect(Artisan::call('campaign:seat', ['slug' => 'ridge-restoration']))->toBe(0)
        ->and(Artisan::output())->toContain('"Ridge Restoration" has recorded no seat.');

    expect(Artisan::call('campaign:seat', ['slug' => 'nobody', 'seat' => 'MA-07']))->toBe(1);
});

test('a seat written by hand that the relation does not name is no seat to compare against', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);

    // Past campaign:seat, which would refuse it, and onto the registry row, which
    // is a JSON column anybody with the database can edit -- or a seat a later
    // release's relation no longer holds. Either way nobody's district can be
    // compared with it, so it must not be treated as though it could.
    $campaign = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $campaign->setAttribute(CampaignSeat::KEY, 'CA-53');
    $campaign->save();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());

    expect(CampaignSeat::stored())->toBe('CA-53')
        ->and(CampaignSeat::current(ZctaDistricts::shipped()))->toBeNull();
});
