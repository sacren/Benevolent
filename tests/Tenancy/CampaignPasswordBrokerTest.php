<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\CampaignPasswordBrokerTenancyBootstrapper;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;

/**
 * A password broker must follow the campaign, and by default it does not.
 *
 * `auth.password` is a singleton whose manager caches each broker it builds,
 * and the DatabaseTokenRepository inside a broker is handed its Connection once
 * and never asks again. So the first campaign to resolve a broker in a given
 * process lends its database to every campaign that follows in that process.
 *
 * Invisible under php-fpm, where each request is its own process. Live wherever
 * one process serves several campaigns: `tenants:run`, and a queue worker
 * taking successive jobs for different campaigns.
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
 * The database the password broker would actually write a reset token into.
 */
function brokerDatabaseName(): string
{
    $repository = Password::broker()->getRepository();

    expect($repository)->toBeInstanceOf(DatabaseTokenRepository::class);

    $connection = (new ReflectionProperty($repository, 'connection'))->getValue($repository);

    return $connection->getDatabaseName();
}

test('each campaign gets a password broker connected to its own database', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // Resolving inside the first campaign is what arms the trap: from here on
    // the manager has a cached broker, and without the bootstrapper it hands
    // that same one -- holding this database -- to every later campaign.
    tenancy()->initialize($harbor);
    $harborDatabase = $harbor->database()->getName();
    expect(brokerDatabaseName())->toBe($harborDatabase);

    tenancy()->initialize($ridge);
    $ridgeDatabase = $ridge->database()->getName();

    // The pairing that makes this a guard rather than a tautology. Asserting
    // only "the broker names Ridge's database" would be satisfied by a broker
    // that happens to name the right one because nothing was cached yet; the
    // two campaigns disagreeing is the whole claim, stated as one assertion so
    // a later edit cannot drop half of it.
    expect($ridgeDatabase)->not->toBe($harborDatabase)
        ->and(brokerDatabaseName())->toBe($ridgeDatabase);
});

test('entering a campaign does not inherit a broker resolved centrally', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    $centralDatabase = (string) config(
        'database.connections.'.config('tenancy.database.central_connection').'.database'
    );

    // The direction the campaign-to-campaign test cannot reach, and the reason
    // this bootstrapper needs a `bootstrap()` at all. Switching between two
    // campaigns already goes through end() -- the package calls it before
    // initializing a different campaign -- so revert() alone would cover that.
    // Nothing calls revert() on the way *in* from central, so a process that
    // resolved a broker before entering campaign context keeps the central
    // connection, and a campaign's reset flow then looks for its tokens in a
    // database that does not carry them (D-1: password_reset_tokens is
    // campaign-only). This is L-15 exactly: resolve something centrally first
    // and the defect hides.
    expect(brokerDatabaseName())->toBe($centralDatabase);

    tenancy()->initialize($harbor);

    expect($harbor->database()->getName())->not->toBe($centralDatabase)
        ->and(brokerDatabaseName())->toBe($harbor->database()->getName());
});

test('leaving a campaign does not leave its password broker behind', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    tenancy()->initialize($harbor);
    expect(brokerDatabaseName())->toBe($harbor->database()->getName());

    tenancy()->end();

    // The same defect pointing the other way: a central caller must not be
    // handed a connection into a campaign's database.
    expect(brokerDatabaseName())
        ->toBe((string) config('database.connections.'.config('tenancy.database.central_connection').'.database'));
});

test('the broker bootstrapper is registered, so the guarantees above are actually wired', function (): void {
    // The configuration invariant behind the three tests above (L-14). Each of
    // them exercises behaviour through the container, and behaviour can be made
    // to look right by accident of ordering; this cannot. Dropping the class
    // from config/tenancy.php turns this red on its own, and turns all three of
    // them red for their own stated reasons -- measured, not assumed.
    //
    // Worth knowing which half guards what, because it is not symmetric.
    // Neutering bootstrap() reddens only the central-to-campaign test;
    // neutering revert() reddens only the campaign-to-central one. The
    // campaign-to-campaign test survives either break on its own, because
    // switching campaigns passes through both hooks and either one suffices --
    // it goes red when the bootstrapper is absent altogether, which is the
    // regression it is really there to report.
    expect(config('tenancy.bootstrappers'))
        ->toContain(CampaignPasswordBrokerTenancyBootstrapper::class);
});
