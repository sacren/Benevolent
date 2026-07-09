<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Provisions a single demo campaign (tenant + its own migrated database).
 *
 * Run standalone so the TenantCreated event fires and the database is
 * provisioned:
 *
 *     php artisan db:seed --class=TenantSeeder
 *
 * Never wire this into DatabaseSeeder: the framework runs nested seeders with
 * WithoutModelEvents, which would mute TenantCreated and skip provisioning.
 */
class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $slug = 'demo-campaign';

        if (Tenant::query()->where('slug', $slug)->exists()) {
            return;
        }

        $tenant = Tenant::create([
            'name' => 'Demo Campaign',
            'slug' => $slug,
        ]);

        // Campaign hostnames use the reserved .test TLD, which local dev resolves
        // via a dnsmasq wildcard (D-2's local-dev realization). Central stays
        // laravel.local.
        $tenant->createDomain(['domain' => 'demo-campaign.test']);
    }
}
