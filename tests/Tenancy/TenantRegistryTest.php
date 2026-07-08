<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    // Creating campaigns below provisions real tenant databases; deleting the
    // tenants drops them so the run leaves nothing behind.
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a campaign stores name and slug as real columns, not in the data JSON', function (): void {
    $tenant = Tenant::create(['name' => 'Acme Campaign', 'slug' => 'acme-campaign']);

    $row = DB::connection('pgsql')->table('tenants')->where('id', $tenant->id)->first();

    // Real, directly-queryable columns...
    expect($row->name)->toBe('Acme Campaign')
        ->and($row->slug)->toBe('acme-campaign');

    // ...not folded into the VirtualColumn `data` JSON.
    $data = json_decode($row->data ?? '{}', true);
    expect($data)->not->toHaveKey('name')
        ->and($data)->not->toHaveKey('slug');
});

test('campaign slugs must be unique', function (): void {
    Tenant::create(['name' => 'First Campaign', 'slug' => 'shared-slug']);

    expect(fn () => Tenant::create(['name' => 'Second Campaign', 'slug' => 'shared-slug']))
        ->toThrow(QueryException::class);
});

test('the tenant factory builds a valid campaign', function (): void {
    $tenant = Tenant::factory()->make();

    expect($tenant->name)->not->toBeEmpty()
        ->and($tenant->slug)->not->toBeEmpty();
});
