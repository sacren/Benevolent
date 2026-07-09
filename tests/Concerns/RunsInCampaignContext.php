<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ProvisionedCampaigns;

/**
 * Runs each test body inside a real, provisioned campaign.
 *
 * Tests using this trait behave like ordinary feature tests — they hit routes,
 * authenticate operators, and assert against `users` — except that the default
 * database connection is the campaign's own database rather than the central
 * one, which is the only place operators exist.
 */
trait RunsInCampaignContext
{
    /**
     * The campaign shared by every test in the using file.
     *
     * Provisioning creates and migrates a real PostgreSQL database (~160ms) and
     * dropping one costs about as much, so a single campaign serves the whole
     * file and each test body runs inside a transaction on its connection: ~20ms
     * per test rather than ~320ms.
     *
     * A trait's static property belongs to the class that uses it, and Pest
     * compiles every test file into its own class — so "static" here already
     * means "once per file" with no bookkeeping of its own.
     */
    protected static ?string $campaignId = null;

    protected Tenant $campaign;

    /**
     * Provision (or reuse) this file's campaign and switch onto its database.
     */
    protected function enterCampaignContext(): void
    {
        $this->campaign = $this->campaignForThisFile();

        tenancy()->initialize($this->campaign);

        // The database exists by now, so a transaction is safe here even though
        // creating it could not run inside one. This is what keeps one test's
        // operators invisible to the next.
        //
        // It survives the HTTP requests these tests make: a request to this same
        // campaign's host re-enters initialize(), which returns early for an
        // already-active campaign instead of reconnecting. A request to a
        // *different* campaign would purge the connection and lose the
        // transaction, so cross-campaign tests provision their own campaigns
        // rather than using this trait.
        DB::connection('tenant')->beginTransaction();
    }

    /**
     * Discard everything the test wrote and leave campaign context.
     */
    protected function leaveCampaignContext(): void
    {
        DB::connection('tenant')->rollBack();

        tenancy()->end();
    }

    /**
     * The campaign's hostname, for requests that must resolve to it.
     */
    protected function campaignUrl(string $path = '/'): string
    {
        $domain = $this->campaign->domains()->value('domain');

        return 'http://'.$domain.'/'.ltrim($path, '/');
    }

    private function campaignForThisFile(): Tenant
    {
        if (static::$campaignId !== null) {
            $campaign = Tenant::query()->find(static::$campaignId);

            if ($campaign instanceof Tenant) {
                return $campaign;
            }

            // Another test file rebuilt the central schema and took this row with
            // it. Its database is already recorded for cleanup, so provision a
            // replacement rather than failing.
            static::$campaignId = null;
        }

        return $this->provisionCampaign();
    }

    private function provisionCampaign(): Tenant
    {
        $central = (string) config('tenancy.database.central_connection');

        if (! Schema::connection($central)->hasTable('tenants')) {
            // This suite gets no transactional RefreshDatabase, so the central
            // schema is built here rather than rolled back per test.
            Artisan::call('migrate:fresh');
        }

        // Named after the using file so a stray database or a failure message
        // points back at the test that produced it.
        $slug = Str::slug(class_basename(static::class)).'-'.Str::lower(Str::random(6));

        $campaign = Tenant::create([
            'name' => 'Harness: '.class_basename(static::class),
            'slug' => $slug,
        ]);

        // .test is reserved for exactly this, so the hostname is safe to use in
        // tests and resolvable in local development.
        $campaign->createDomain(['domain' => $slug.'.test']);

        static::$campaignId = (string) $campaign->getTenantKey();

        ProvisionedCampaigns::track(static::$campaignId, $campaign->database()->getName());

        return $campaign;
    }
}
