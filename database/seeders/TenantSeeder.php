<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\OperatorRole;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use App\Tenancy\CampaignContact;
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

    /**
     * Where a supporter's reply to a demo blast would come back to.
     *
     * Seeded because a campaign with no reply address is a legitimate state
     * that the send path has to handle, and therefore the *less* useful one to
     * meet by default when opening the application to look at it. The state
     * with no address is reachable by creating a second campaign without one.
     */
    private const CONTACT_ADDRESS = 'replies@demo-campaign.test';

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

        // Set every run rather than only on creation, so a demo campaign made
        // before this value existed acquires one -- the same shape as the
        // operator promotion above, and the reason this seeder is safe to
        // re-run at all.
        CampaignContact::store($campaign, self::CONTACT_ADDRESS);

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
     * **Each row is matched on the address and brought up to date, because
     * email is the identity (D-8).** The match goes through the model's own
     * case-folding scope rather than comparing the column, so an address an
     * operator has since entered with different casing is recognised rather
     * than inserted a second time and refused by the unique index.
     *
     * **"Safe to re-run" means two guarantees, and conflating them cost a
     * jurisdiction change.** It has always meant *re-running adds nothing
     * twice*, which tests/Tenancy/DemoCampaignSeedingTest.php holds. A reader
     * takes it to also mean *re-running brings a campaign up to date*, and
     * until Phase 4 Step 2 it did not: this method guarded each row with an
     * existence check and called create() only, so a change to any column other
     * than the address never reached a campaign that already had it. The
     * consequence was found rather than imagined -- the demo campaign kept UK
     * postcodes through the move to US ZIP codes, and re-seeding would have
     * changed nothing while reporting success.
     *
     * **So the write repairs now, and the second guarantee is held by a test of
     * its own** rather than inferred from the first. The cost is accepted and
     * stated: re-seeding overwrites a demo supporter an operator edited by
     * hand. That is the right trade for a fixture campaign whose purpose is to
     * be a known state, and re-seeding is a deliberate act rather than
     * something that happens on its own.
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
                'postcode' => '90210',
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
                'postcode' => '02139',
                'subscription_status' => SubscriptionStatus::Subscribed,
            ],
            [
                'name' => 'Ines Duarte',
                'given_name' => 'Ines',
                'family_name' => 'Duarte',
                'email' => 'Ines.Duarte@Example.test',
                'postcode' => '73301',
                'subscription_status' => SubscriptionStatus::Unsubscribed,
            ],
        ];

        foreach ($supporters as $supporter) {
            $existing = Supporter::query()
                ->whereEmailMatches($supporter['email'])
                ->first();

            if ($existing instanceof Supporter) {
                // Update rather than skip, so a fixture the seeder owns reaches
                // a campaign that already exists. forceFill() because the
                // address is deliberately not fillable -- this is the seeder
                // restating its own row, not a form editing somebody's.
                $existing->forceFill($supporter)->save();

                continue;
            }

            Supporter::query()->create($supporter);
        }
    }
}
