<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\CampaignContact;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('campaign:create {name : The campaign name} {domain? : Optional domain to associate with the campaign} {--contact= : Optional address the campaign\'s mail should come back to}')]
#[Description('Provision a new campaign: create its tenant record and its own migrated database.')]
class CreateCampaign extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $slug = Str::slug($name);

        // Checked before anything is provisioned, so a typo costs a message
        // rather than a half-made campaign that has to be deleted. The address
        // is optional (see CampaignContact); a *malformed* one is not the same
        // thing as none, and silently dropping it would leave the operator
        // believing replies were going somewhere.
        $contact = $this->option('contact');

        if (is_string($contact) && ! CampaignContact::usable($contact)) {
            $this->components->error("\"{$contact}\" is not a usable reply address.");

            return self::FAILURE;
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            $this->components->error("A campaign with the slug \"{$slug}\" already exists.");

            return self::FAILURE;
        }

        // Creating the tenant fires TenantCreated, whose CreateDatabase →
        // MigrateDatabase pipeline provisions and migrates the tenant's own
        // PostgreSQL database synchronously.
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        if (($domain = $this->argument('domain')) !== null) {
            $tenant->createDomain(['domain' => $domain]);
        }

        if (is_string($contact)) {
            CampaignContact::store($tenant, $contact);
        }

        $this->components->info("Campaign \"{$name}\" provisioned (slug: {$slug}, database: {$tenant->database()->getName()}).");

        return self::SUCCESS;
    }
}
