<?php

declare(strict_types=1);

use App\Providers\TenancyServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction. Transactional
    // RefreshDatabase is incompatible with tenant provisioning (a later step)
    // because PostgreSQL forbids CREATE DATABASE inside a transaction block, so
    // the whole Tenancy suite manages its schema explicitly.
    Artisan::call('migrate:fresh');
});

test('central migrations create the tenant registry tables', function (): void {
    expect(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('domains'))->toBeTrue();
});

test('tenancy config is published and targets database-per-tenant on postgres', function (): void {
    expect(config('tenancy.tenant_model'))->toBe(Tenant::class)
        ->and(config('tenancy.database.central_connection'))->toBe('pgsql')
        ->and(config('tenancy.database.managers.pgsql'))->toBe(PostgreSQLDatabaseManager::class);
});

test('the tenancy service provider is registered and the manager resolves', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(TenancyServiceProvider::class)
        ->and(tenancy())->toBeInstanceOf(Tenancy::class)
        ->and(tenancy()->initialized)->toBeFalse();
});
