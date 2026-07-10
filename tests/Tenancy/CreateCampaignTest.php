<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    // Leave campaign context and close the connection to the campaign's database
    // first: PostgreSQL refuses to drop a database that still has a session on
    // it, and a test that signed in leaves one open.
    tenancy()->end();
    DB::purge('tenant');

    // Every provisioned campaign owns a real database; deleting the tenant fires
    // the DeleteDatabase job so the run leaves nothing behind.
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('the command provisions a working campaign with its own migrated database', function (): void {
    $exitCode = Artisan::call('campaign:create', [
        'name' => 'Grassroots Drive',
        'domain' => 'grassroots.test',
    ]);

    expect($exitCode)->toBe(0);

    $tenant = Tenant::query()->where('slug', 'grassroots-drive')->firstOrFail();
    expect($tenant->name)->toBe('Grassroots Drive');

    // The optional domain argument created a registry row for resolution (Step 5).
    expect(DB::connection('pgsql')->table('domains')
        ->where('tenant_id', $tenant->id)
        ->where('domain', 'grassroots.test')
        ->exists())->toBeTrue();

    // The tenant's own database was provisioned and migrated: initializing tenancy
    // switches the connection onto it, and the migrations table proves migrate ran.
    tenancy()->initialize($tenant);
    expect(DB::connection()->getDatabaseName())->toBe('tenant'.$tenant->id)
        ->and(Schema::hasTable('migrations'))->toBeTrue();
    tenancy()->end();

    expect(tenancy()->initialized)->toBeFalse();
});

test('a duplicate slug is rejected with no partial provisioning', function (): void {
    Artisan::call('campaign:create', ['name' => 'Save the Bay']);
    $tenant = Tenant::query()->where('slug', 'save-the-bay')->firstOrFail();

    // A different name that slugs to the same value must fail fast.
    $exitCode = Artisan::call('campaign:create', ['name' => 'SAVE THE BAY']);

    expect($exitCode)->toBe(1)
        ->and(Tenant::query()->where('slug', 'save-the-bay')->count())->toBe(1)
        ->and($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeTrue();
});

test('deleting a tenant drops its database', function (): void {
    Artisan::call('campaign:create', ['name' => 'Clean Water Now']);
    $tenant = Tenant::query()->where('slug', 'clean-water-now')->firstOrFail();

    $manager = $tenant->database()->manager();
    $databaseName = $tenant->database()->getName();
    expect($manager->databaseExists($databaseName))->toBeTrue();

    $tenant->delete();

    expect($manager->databaseExists($databaseName))->toBeFalse();
});

test('the tenant seeder provisions the demo campaign', function (): void {
    Artisan::call('db:seed', ['--class' => 'TenantSeeder']);

    $tenant = Tenant::query()->where('slug', 'demo-campaign')->firstOrFail();

    expect($tenant->name)->toBe('Demo Campaign')
        ->and($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeTrue()
        ->and(DB::connection('pgsql')->table('domains')
            ->where('tenant_id', $tenant->id)
            ->where('domain', 'demo-campaign.test')
            ->exists())->toBeTrue();
});

test('the seeded demo operator can sign in on the demo campaign host', function (): void {
    // The whole point of seeding an operator: this is the browser check, run as
    // a test. A seeded row that cannot actually authenticate would be worse than
    // no seed at all, because it would look done.
    Artisan::call('db:seed', ['--class' => 'TenantSeeder']);

    $response = $this->post('http://demo-campaign.test/login', [
        'email' => 'operator@demo-campaign.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the tenant seeder can be run twice without complaint', function (): void {
    // Every step checks for itself, so a second run is a no-op rather than a
    // duplicate campaign, a duplicate hostname, or a unique-constraint failure
    // on the operator's email.
    Artisan::call('db:seed', ['--class' => 'TenantSeeder']);
    Artisan::call('db:seed', ['--class' => 'TenantSeeder']);

    $campaigns = Tenant::query()->where('slug', 'demo-campaign')->get();

    expect($campaigns)->toHaveCount(1)
        ->and($campaigns->first()->domains()->count())->toBe(1)
        ->and($campaigns->first()->run(fn () => User::query()->count()))->toBe(1);
});
