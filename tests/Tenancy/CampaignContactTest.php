<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\CampaignContact;
use Illuminate\Support\Facades\Artisan;

/**
 * Each campaign's mail comes back to that campaign's own address.
 *
 * The first real consumer of the per-campaign setting substrate Phase 0 Step 14
 * built and guarded, so this file guards the *value* where
 * CampaignSettingsStorageTest guards the mechanism it sits on.
 *
 * **Two campaigns throughout, never one (L-21).** The failure this project has
 * measured five times over is a value captured once and served to every
 * campaign after -- a cached connection, a cached broker, a cached mailer, a
 * rate-limit counter, a lock name. A reply address is exactly that kind of
 * value: it is read once per message, from an object a long-lived worker holds
 * across jobs, and with a single campaign "the first" and "the only" cannot be
 * told apart.
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

/**
 * The campaign as a request or a job would find it: read from the registry
 * rather than reused, so nothing below can read back the object it wrote to.
 */
function campaignNamed(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

test('each campaign reads its own reply address, and still does after another is served', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', '--contact' => 'crew@harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', '--contact' => 'trail@ridge-restoration.test']);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    expect(CampaignContact::address())->toBe('crew@harbor-cleanup.test');

    tenancy()->initialize(campaignNamed('ridge-restoration'));

    // Stated as one assertion so a later edit cannot drop half of it. That Ridge
    // reads its own address is satisfied by a process where nothing had been
    // captured yet; the two campaigns *disagreeing* is the claim.
    expect(CampaignContact::address())->toBe('trail@ridge-restoration.test')
        ->and(CampaignContact::address())->not->toBe('crew@harbor-cleanup.test');

    // Back to the campaign served first. A value captured once and reused would
    // still look right here -- the middle assertion is what catches that -- but
    // a switch that only ever moves forward would not, and a worker taking one
    // campaign's blast after another's does return to campaigns it has served.
    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    expect(CampaignContact::address())->toBe('crew@harbor-cleanup.test');
});

test('a campaign with no reply address reads none, rather than inheriting one', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', '--contact' => 'crew@harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration']);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    $harborReads = CampaignContact::address();

    tenancy()->initialize(campaignNamed('ridge-restoration'));

    // The negative claim carries the positive one, made through the same call in
    // the same run (L-19). "Ridge has no reply address" is satisfied perfectly by
    // a mechanism that never returns anything for anybody, so on its own it
    // would pass against storage that does not work at all.
    expect(CampaignContact::address())->toBeNull()
        ->and($harborReads)->toBe('crew@harbor-cleanup.test');
});

test('outside a campaign there is no reply address, rather than a platform default', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', '--contact' => 'crew@harbor-cleanup.test']);

    // Both directions matter and they are different runs: a console command
    // starts here, and a queue worker is here again between jobs. A wrong answer
    // in either place is a campaign's reply address on another campaign's mail,
    // or on the platform's.
    expect(CampaignContact::address())->toBeNull();

    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    expect(CampaignContact::address())->toBe('crew@harbor-cleanup.test');

    tenancy()->end();
    expect(CampaignContact::address())->toBeNull();
});

test('the address is stored on the registry row and costs no query to read', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', '--contact' => 'crew@harbor-cleanup.test']);

    // The configuration invariant behind the behaviour above. `contact_address`
    // is not in getCustomColumns(), so VirtualColumn folds it into `data` and no
    // migration was owed -- and the campaign's own record is the object tenancy
    // is already holding, so reading it inside campaign context issues nothing.
    expect(Tenant::getCustomColumns())->not->toContain(CampaignContact::KEY);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(CampaignContact::address())->toBe('crew@harbor-cleanup.test')
        ->and($queries)->toBe(0);
});

test('a campaign is not provisioned at all when its reply address is malformed', function (): void {
    $exitCode = Artisan::call('campaign:create', [
        'name' => 'Harbor Cleanup',
        '--contact' => 'crew at harbor-cleanup dot test',
    ]);

    // Refused *before* anything is created, so a typo costs a message rather
    // than a half-made campaign with a real database behind it that somebody
    // then has to notice and delete.
    expect($exitCode)->toBe(1)
        ->and(Tenant::query()->where('slug', 'harbor-cleanup')->exists())->toBeFalse();
});

test('a campaign that already exists can be given a reply address, and have it changed', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup']);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    expect(CampaignContact::address())->toBeNull();
    tenancy()->end();

    expect(Artisan::call('campaign:contact', ['slug' => 'harbor-cleanup', 'address' => 'crew@harbor-cleanup.test']))->toBe(0);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    expect(CampaignContact::address())->toBe('crew@harbor-cleanup.test');
    tenancy()->end();

    // Changed rather than only set, because an address outlives nobody in
    // particular and the campaigns that need this command most are the ones
    // whose address has moved.
    expect(Artisan::call('campaign:contact', ['slug' => 'harbor-cleanup', 'address' => 'hello@harbor-cleanup.test']))->toBe(0);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));
    expect(CampaignContact::address())->toBe('hello@harbor-cleanup.test');
});

test('a malformed address does not replace the one a campaign already has', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', '--contact' => 'crew@harbor-cleanup.test']);

    expect(Artisan::call('campaign:contact', ['slug' => 'harbor-cleanup', 'address' => 'nobody']))->toBe(1);

    tenancy()->initialize(campaignNamed('harbor-cleanup'));

    // The direction that matters. A refusal that had already written would be
    // reported as a failure while having replaced a working address with a
    // broken one -- worse than either succeeding or failing cleanly.
    expect(CampaignContact::address())->toBe('crew@harbor-cleanup.test');
});

test('an address written by hand as blank is no address, not a blank header', function (): void {
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', '--contact' => 'crew@harbor-cleanup.test']);

    // Written straight onto the registry row rather than through store(), which
    // trims, and past campaign:contact, which refuses it. **That is the only
    // route this case has, and it is a real one**: `tenants.data` is a JSON
    // column set by hand and by console commands, which is the whole reason
    // Phase 0 Step 14 could build the substrate with no migration. The column is
    // the input, exactly as BlastAudience says of `postcode_prefixes`.
    //
    // Found because breaking the branch reddened nothing at all: every other
    // test here reaches address() through a path that has already rejected or
    // trimmed the value, so the branch was unreachable from all eight. That is
    // L-23's shape -- a guard the inputs cannot reach -- and it is the second
    // time this project has met it through a writer rather than through
    // arithmetic.
    $campaign = campaignNamed('harbor-cleanup');
    $campaign->setAttribute(CampaignContact::KEY, '   ');
    $campaign->save();

    tenancy()->initialize(campaignNamed('harbor-cleanup'));

    // Null rather than the whitespace, and null rather than the platform's own
    // address: a message with `Reply-To: "   "` is a malformed header on mail
    // already delivered, and a message quietly answering to the platform is a
    // supporter's reply going somewhere no campaign reads.
    expect(CampaignContact::address())->toBeNull()
        ->and(CampaignContact::address())->not->toBe(config('mail.from.address'));
});

test('the seeded demo campaign has a reply address', function (): void {
    Artisan::call('db:seed', ['--class' => 'TenantSeeder']);

    tenancy()->initialize(campaignNamed('demo-campaign'));

    expect(CampaignContact::address())->toBe('replies@demo-campaign.test');
});
