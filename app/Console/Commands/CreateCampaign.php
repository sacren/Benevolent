<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('campaign:create {name : The campaign name} {domain? : Optional domain to associate with the campaign}')]
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

        $this->components->info("Campaign \"{$name}\" provisioned (slug: {$slug}, database: {$tenant->database()->getName()}).");

        return self::SUCCESS;
    }
}
