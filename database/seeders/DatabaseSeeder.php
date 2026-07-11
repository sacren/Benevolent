<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds nothing centrally, and that is the point: operators live in their own
 * campaign's database, so there is no such thing as a central user to create.
 * This class used to seed one, which fails outright the moment the `users`
 * table leaves the central database.
 *
 * Two things to know before filling this in:
 *
 * - `config/tenancy.php` names this class as the root seeder for the package's
 *   SeedDatabase job, so whatever lands here would run inside each *new
 *   campaign's* database rather than the central one. That job is currently
 *   commented out of the provisioning pipeline in TenancyServiceProvider, so
 *   nothing runs it today.
 * - The demo campaign lives in TenantSeeder, which is run standalone
 *   (`db:seed --class=TenantSeeder`). Never call it from here: the framework
 *   runs nested seeders with WithoutModelEvents, which would mute TenantCreated
 *   and skip provisioning the campaign's database entirely.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        //
    }
}
