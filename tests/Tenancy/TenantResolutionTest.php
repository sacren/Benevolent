<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
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

/**
 * Creates an operator inside one campaign's own database.
 */
function operatorFor(string $slug, string $email): void
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    User::factory()->create(['email' => $email]);

    tenancy()->end();
}

test('an operator signs in on their own campaign host', function (): void {
    Artisan::call('campaign:create', [
        'name' => 'Grassroots Drive',
        'domain' => 'grassroots.test',
    ]);

    operatorFor('grassroots-drive', 'organizer@grassroots.test');

    $response = $this->post('http://grassroots.test/login', [
        'email' => 'organizer@grassroots.test',
        'password' => 'password',
    ]);

    // Reaching an authenticated session at all is the resolution proof. This
    // operator exists in exactly one database, so only a request that resolved
    // the campaign and switched onto that database could have found them.
    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('an operator cannot sign in on another campaign host', function (): void {
    Artisan::call('campaign:create', ['name' => 'Save the Bay', 'domain' => 'save-the-bay.test']);
    Artisan::call('campaign:create', ['name' => 'Clean Water Now', 'domain' => 'clean-water-now.test']);

    operatorFor('save-the-bay', 'organizer@save-the-bay.test');

    // The same credentials against a different campaign's hostname. This states
    // the isolation guarantee as behaviour rather than as a fact about schemas:
    // one campaign's operators are invisible to another because they are in
    // another database entirely.
    $response = $this->post('http://clean-water-now.test/login', [
        'email' => 'organizer@save-the-bay.test',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('a campaign route works under the database session driver', function (): void {
    // The suite runs with SESSION_DRIVER=array, so this is the one place the
    // real dev/production driver is exercised. Tenancy switches the default
    // connection onto the campaign's database ahead of StartSession, and the
    // `sessions` table is central-only — so an unpinned session connection
    // would 500 here while the rest of the suite stayed green.
    config(['session.driver' => 'database']);

    Artisan::call('campaign:create', ['name' => 'Session Probe', 'domain' => 'session-probe.test']);

    $this->withoutExceptionHandling();

    $this->get('http://session-probe.test/login')->assertOk();

    expect(config('session.connection'))->toBe(config('tenancy.database.central_connection'));
});

test('an unregistered host cannot reach a campaign route', function (): void {
    $this->withoutExceptionHandling();

    // No `domains` row exists for this host, so identification fails and the
    // request never reaches the route.
    expect(fn () => $this->get('http://not-a-campaign.test/login'))
        ->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class)
        ->and(tenancy()->initialized)->toBeFalse();
});
