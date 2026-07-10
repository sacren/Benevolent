<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Provisions a demo campaign — the tenant, its own migrated database, its
 * hostname, and one operator inside it to sign in as.
 *
 * Run standalone so the TenantCreated event fires and the database is
 * provisioned:
 *
 *     php artisan db:seed --class=TenantSeeder
 *
 * Never wire this into DatabaseSeeder: the framework runs nested seeders with
 * WithoutModelEvents, which would mute TenantCreated and skip provisioning.
 *
 * Each step checks for itself before running, so this is safe to re-run and
 * will add the operator to a demo campaign that was created some other way.
 */
class TenantSeeder extends Seeder
{
    private const SLUG = 'demo-campaign';

    private const NAME = 'Demo Campaign';

    /**
     * Campaign hostnames use the reserved .test TLD, so a local resolver can
     * answer for them without any of this reaching the public DNS.
     */
    private const DOMAIN = 'demo-campaign.test';

    private const OPERATOR_EMAIL = 'operator@demo-campaign.test';

    private const OPERATOR_PASSWORD = 'password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            // This creates an account whose password is published in this file.
            // It exists to save a trip through Mailpit during development and
            // has no business anywhere real.
            $this->command->error('TenantSeeder seeds a known password and will not run in production.');

            return;
        }

        $campaign = Tenant::query()->where('slug', self::SLUG)->first()
            ?? Tenant::create(['name' => self::NAME, 'slug' => self::SLUG]);

        if (! $campaign->domains()->where('domain', self::DOMAIN)->exists()) {
            $campaign->createDomain(['domain' => self::DOMAIN]);
        }

        $campaign->run(function (): void {
            if (User::query()->where('email', self::OPERATOR_EMAIL)->exists()) {
                return;
            }

            // Verified on creation: the dashboard is behind the `verified`
            // middleware, and the point of seeding an operator is to reach it
            // without registering and clicking through a mail client first.
            User::factory()->create([
                'name' => 'Demo Operator',
                'email' => self::OPERATOR_EMAIL,
                'password' => self::OPERATOR_PASSWORD,
            ]);
        });

        $this->command->info(sprintf(
            'Demo campaign ready — sign in at %s/login as %s with password "%s".',
            self::DOMAIN,
            self::OPERATOR_EMAIL,
            self::OPERATOR_PASSWORD,
        ));
    }
}
