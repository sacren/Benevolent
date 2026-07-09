<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    // Every provisioned campaign owns a real database; deleting the tenant fires
    // the DeleteDatabase job so the run leaves nothing behind.
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a request to a campaign domain resolves the campaign and switches the database connection', function (): void {
    Artisan::call('campaign:create', [
        'name' => 'Grassroots Drive',
        'domain' => 'grassroots.test',
    ]);

    $campaign = Tenant::query()->where('slug', 'grassroots-drive')->firstOrFail();

    $response = $this->get('http://grassroots.test/campaign');

    $response->assertOk()
        ->assertJson([
            'campaign' => [
                'id' => $campaign->id,
                'name' => 'Grassroots Drive',
                'slug' => 'grassroots-drive',
            ],
            // The connection the route ran on was the campaign's own database,
            // not the central one — this is the resolution proof.
            'database' => 'tenant'.$campaign->id,
        ]);
});

test('each campaign domain resolves to its own database', function (): void {
    Artisan::call('campaign:create', ['name' => 'Save the Bay', 'domain' => 'save-the-bay.test']);
    Artisan::call('campaign:create', ['name' => 'Clean Water Now', 'domain' => 'clean-water-now.test']);

    $bay = Tenant::query()->where('slug', 'save-the-bay')->firstOrFail();
    $water = Tenant::query()->where('slug', 'clean-water-now')->firstOrFail();

    $this->get('http://save-the-bay.test/campaign')
        ->assertOk()
        ->assertJsonPath('campaign.slug', 'save-the-bay')
        ->assertJsonPath('database', 'tenant'.$bay->id);

    $this->get('http://clean-water-now.test/campaign')
        ->assertOk()
        ->assertJsonPath('campaign.slug', 'clean-water-now')
        ->assertJsonPath('database', 'tenant'.$water->id);
});

test('a tenant route works under the database session driver', function (): void {
    // The suite runs with SESSION_DRIVER=array, so this is the one place the
    // real dev/production driver is exercised. Tenancy switches the default
    // connection onto the campaign's database ahead of StartSession, and the
    // `sessions` table is central-only — so an unpinned session connection
    // would 500 here while the rest of the suite stayed green.
    config(['session.driver' => 'database']);

    Artisan::call('campaign:create', ['name' => 'Session Probe', 'domain' => 'session-probe.test']);

    $this->withoutExceptionHandling();

    $this->get('http://session-probe.test/campaign')->assertOk();

    expect(config('session.connection'))->toBe(config('tenancy.database.central_connection'));
});

test('an unregistered host cannot reach a tenant route', function (): void {
    $this->withoutExceptionHandling();

    // No `domains` row exists for this host, so identification fails and the
    // request never reaches the route.
    expect(fn () => $this->get('http://not-a-campaign.test/campaign'))
        ->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class)
        ->and(tenancy()->initialized)->toBeFalse();
});
