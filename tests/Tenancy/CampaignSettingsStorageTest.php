<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Features\TenantConfig;

/**
 * A setting kept on a campaign's registry row answers for that campaign only.
 *
 * This guards the substrate rather than any particular setting. Step 14 set out
 * to build feature enablement -- which modules a campaign can see -- and found
 * the mechanism already provided: a value assigned to a campaign folds into the
 * registry's `data` JSON through VirtualColumn with no migration, and reading it
 * inside campaign context costs no query, because the campaign's own record is
 * already in memory. What Step 14 could not supply is the vocabulary. Phase 0
 * has no modules, so a feature set written today would have no cases and every
 * test of it would assert that a setter and a getter agree.
 *
 * So the vocabulary is deferred to the first module that needs one, and what is
 * guarded here is what that module will sit on. `enabled_modules` below is this
 * test's own key -- nothing in the application reads it, and that is deliberate
 * twice over. It stands for whatever the first per-campaign setting turns out to
 * be, and because no future change would promote it to a real column, the
 * storage assertion at the bottom cannot fire on work Phase 1 is meant to do.
 *
 * Two campaigns throughout, never one (L-21). The failure this project has
 * measured five times is a value captured once and served to every campaign
 * after -- a cached connection, a cached broker, a cached mailer, a rate-limit
 * counter, a lock name. With a single campaign, "the first" and "the only" are
 * the same campaign and every one of those passes.
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
 * Store a setting against a campaign, addressed by slug.
 *
 * Always loads the campaign afresh from the registry, so no test below can read
 * back the same object it wrote to. Round-tripping an attribute on one in-memory
 * model would prove only that PHP holds values, which is the shape of test this
 * step exists to avoid writing.
 */
function storeCampaignSetting(string $slug, string $key, mixed $value): void
{
    $campaign = Tenant::query()->where('slug', $slug)->firstOrFail();

    $campaign->$key = $value;
    $campaign->save();
}

/**
 * The campaign as a request would find it: read from the registry, not reused.
 */
function campaignFromRegistry(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

test('each campaign reads its own settings, and keeps doing so after another campaign is served', function (): void {
    storeCampaignSetting('harbor-cleanup', 'enabled_modules', ['blasts']);
    storeCampaignSetting('ridge-restoration', 'enabled_modules', ['donations']);

    tenancy()->initialize(campaignFromRegistry('harbor-cleanup'));
    expect(tenant('enabled_modules'))->toBe(['blasts']);

    tenancy()->initialize(campaignFromRegistry('ridge-restoration'));

    // Stated as one assertion so a later edit cannot drop half of it. That Ridge
    // reads `donations` is satisfied by a process where nothing had been
    // captured yet; the two campaigns disagreeing is the claim.
    expect(tenant('enabled_modules'))->toBe(['donations'])
        ->and(tenant('enabled_modules'))->not->toBe(['blasts']);

    // Back to the campaign served first. A value captured once and reused would
    // still look right here -- it is the middle assertion that catches that --
    // but a switch that only ever moves forward would not, and a worker taking
    // one campaign's job after another's does return to campaigns it has
    // already served.
    tenancy()->initialize(campaignFromRegistry('harbor-cleanup'));
    expect(tenant('enabled_modules'))->toBe(['blasts']);
});

test('a setting one campaign has is absent from a campaign that does not, rather than inherited', function (): void {
    storeCampaignSetting('harbor-cleanup', 'enabled_modules', ['blasts']);

    tenancy()->initialize(campaignFromRegistry('harbor-cleanup'));
    $harborReads = tenant('enabled_modules');

    tenancy()->initialize(campaignFromRegistry('ridge-restoration'));

    // The negative claim carries the positive one made through the same call in
    // the same run (L-19). "Ridge has no modules" is satisfied perfectly by a
    // mechanism that never returns anything for anyone, so on its own it would
    // pass against storage that does not work at all.
    expect(tenant('enabled_modules'))->toBeNull()
        ->and($harborReads)->toBe(['blasts']);
});

test('outside a campaign a setting reads as absent, not as a platform default', function (): void {
    storeCampaignSetting('harbor-cleanup', 'enabled_modules', ['blasts']);

    // Before any campaign is entered, and again after one is left. Both
    // directions matter: a console command or a worker between jobs runs here,
    // and a wrong answer in either direction is the same wrong answer.
    expect(tenant('enabled_modules'))->toBeNull();

    tenancy()->initialize(campaignFromRegistry('harbor-cleanup'));
    expect(tenant('enabled_modules'))->toBe(['blasts']);

    tenancy()->end();
    expect(tenant('enabled_modules'))->toBeNull();

    // Why absence is the property worth pinning, rather than an incidental
    // detail of the read. The package ships Stancl\Tenancy\Features\TenantConfig,
    // which maps a campaign's stored keys onto config keys; measured, it works
    // and restores central values correctly on the way out. It was declined for
    // this because of what it does to the read: a campaign's setting reached
    // through config() answers with the *platform default* whenever no campaign
    // is active, and for any campaign that has not set it. A question about one
    // campaign would then get a plausible answer in a context where the honest
    // answer is that there is no campaign to ask about -- the same shape as a
    // central-table consumer following the switched connection (L-7).
    expect(config('tenancy.features'))->not->toContain(TenantConfig::class);
});

test('a campaign setting needs no schema change, because it lives in the registry data column', function (): void {
    storeCampaignSetting('harbor-cleanup', 'enabled_modules', ['blasts']);

    $campaign = campaignFromRegistry('harbor-cleanup');
    $row = DB::connection('pgsql')->table('tenants')->where('id', $campaign->id)->first();

    // The configuration invariant behind the three behavioural tests (L-14).
    // Those exercise VirtualColumn through the model, and model behaviour can
    // look right for reasons of ordering; where the bytes landed cannot. It is
    // also the claim that decided this step's shape -- a per-campaign setting
    // costs no migration, so nothing about adding one reaches campaigns that
    // already exist (L-17), and Phase 1 adds its first feature set by writing to
    // a campaign rather than by altering a table.
    expect(Tenant::getCustomColumns())->not->toContain('enabled_modules')
        ->and(Schema::connection('pgsql')->getColumnListing('tenants'))->not->toContain('enabled_modules')
        ->and(json_decode((string) $row->data, true))->toHaveKey('enabled_modules');
});
