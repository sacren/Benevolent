<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    // The L-1 regression guard below provisions a real tenant database; deleting
    // the tenant fires the DeleteDatabase job so nothing is left behind.
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('the application tenant model is database-capable', function (): void {
    expect(new Tenant)->toBeInstanceOf(TenantWithDatabase::class);
});

test('tenancy config points at the application tenant model', function (): void {
    expect(config('tenancy.tenant_model'))->toBe(Tenant::class);
});

test('creating a tenant provisions its own database without error', function (): void {
    // L-1 regression guard: the base package model is not TenantWithDatabase, so
    // Tenant::create() used to throw in the CreateDatabase pipeline. Full
    // provisioning coverage (command, migrate, delete) lands in Step 5.
    $tenant = Tenant::create();

    expect($tenant->database()->getName())->toBe('tenant'.$tenant->id);
});
