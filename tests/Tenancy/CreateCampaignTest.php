<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    // Every provisioned campaign owns a real database; deleting the tenant fires
    // the DeleteDatabase job so the run leaves nothing behind.
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('the command provisions a working campaign with its own migrated database', function (): void {
    $exitCode = Artisan::call('campaign:create', [
        'name' => 'Grassroots Drive',
        'domain' => 'grassroots.localhost',
    ]);

    expect($exitCode)->toBe(0);

    $tenant = Tenant::query()->where('slug', 'grassroots-drive')->firstOrFail();
    expect($tenant->name)->toBe('Grassroots Drive');

    // The optional domain argument created a registry row for resolution (Step 5).
    expect(DB::connection('pgsql')->table('domains')
        ->where('tenant_id', $tenant->id)
        ->where('domain', 'grassroots.localhost')
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
            ->where('domain', 'demo-campaign.localhost')
            ->exists())->toBeTrue();
});
