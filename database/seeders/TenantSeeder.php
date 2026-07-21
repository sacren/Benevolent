<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\OperatorRole;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
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
            $operator = User::query()->where('email', self::OPERATOR_EMAIL)->first();

            if ($operator === null) {
                // Verified on creation: the dashboard is behind the `verified`
                // middleware, and the point of seeding an operator is to reach it
                // without registering and clicking through a mail client first.
                User::factory()->owner()->create([
                    'name' => 'Demo Operator',
                    'email' => self::OPERATOR_EMAIL,
                    'password' => self::OPERATOR_PASSWORD,
                ]);

                return;
            }

            // The operator already exists, which is the case on any demo
            // campaign seeded before roles did. Returning early here would leave
            // it as whatever the role column defaulted to when the migration
            // added it -- Staff -- and the demo campaign's only account would be
            // unable to exercise anything an Owner may do. So the seeder ensures
            // the role rather than only creating the account.
            $operator->role = OperatorRole::Owner;
            $operator->save();
        });

        $campaign->run(fn () => $this->seedSupporters());

        $this->command->info(sprintf(
            'Demo campaign ready — sign in at %s/login as %s with password "%s".',
            self::DOMAIN,
            self::OPERATOR_EMAIL,
            self::OPERATOR_PASSWORD,
        ));
    }

    /**
     * Give the demo campaign a list worth looking at.
     *
     * Each row is one shape a real list actually contains, so the supporter
     * page is exercised against the data the schema was designed for rather
     * than against four tidy rows that all look the same:
     *
     * - a source that split the name, which most advocacy exports do;
     * - a source that gave one string, so both parts stay null rather than
     *   being guessed at -- a mononym, where there is no boundary to find;
     * - an address with no name at all, which a petition widget produces;
     * - someone who has asked not to be contacted, which is kept rather than
     *   deleted so a later import cannot put them back.
     *
     * Each row is added only if the address is not already present, because
     * email is the identity (D-8) and this seeder is documented as safe to
     * re-run -- a claim tests/Tenancy/DemoCampaignSeedingTest.php now holds it
     * to. The check goes through the model's own case-folding scope rather than
     * comparing the column, so an address an operator has since entered with
     * different casing is recognised rather than inserted a second time and
     * refused by the unique index.
     *
     * One address is deliberately mixed-case. Real exports carry them, and it
     * puts the "stored exactly as given" half of D-8 on the page where it can
     * be seen rather than only in a test.
     */
    private function seedSupporters(): void
    {
        $supporters = [
            [
                'name' => 'Ama Boateng',
                'given_name' => 'Ama',
                'family_name' => 'Boateng',
                'email' => 'ama.boateng@example.test',
                'postcode' => 'M15 6BH',
                'subscription_status' => SubscriptionStatus::Subscribed,
            ],
            [
                'name' => 'Sukarno',
                'given_name' => null,
                'family_name' => null,
                'email' => 'sukarno@example.test',
                'postcode' => null,
                'subscription_status' => SubscriptionStatus::Subscribed,
            ],
            [
                'name' => null,
                'given_name' => null,
                'family_name' => null,
                'email' => 'petition-signer@example.test',
                'postcode' => 'EH8 9YL',
                'subscription_status' => SubscriptionStatus::Subscribed,
            ],
            [
                'name' => 'Ines Duarte',
                'given_name' => 'Ines',
                'family_name' => 'Duarte',
                'email' => 'Ines.Duarte@Example.test',
                'postcode' => '1250-096',
                'subscription_status' => SubscriptionStatus::Unsubscribed,
            ],
        ];

        foreach ($supporters as $supporter) {
            $exists = Supporter::query()
                ->whereEmailMatches($supporter['email'])
                ->exists();

            if (! $exists) {
                Supporter::query()->create($supporter);
            }
        }
    }
}
