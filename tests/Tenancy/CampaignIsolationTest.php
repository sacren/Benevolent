<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The DEC-1 gate: campaign A can never read campaign B's data.
 *
 * DEC-1 chose database-per-tenant, and its v0.6 amendment records physical
 * separation as a deliberate choice rather than an external mandate — so these
 * are sanity checks today. Should a mandate ever reclassify that choice as
 * required, this file becomes the compliance evidence and should be read with
 * that weight.
 *
 * What the guarantee rests on, stated plainly so nobody mistakes its shape: an
 * application in campaign context holds one connection, to one database, and
 * PostgreSQL refuses to reach across databases at all. It is architectural, not
 * credential-based — every campaign database is owned by the same PostgreSQL
 * role, so code that deliberately opened a connection to another campaign's
 * database by name would succeed. Closing that would mean a database user per
 * campaign, which the tenancy package implements for MySQL only.
 *
 * These tests provision their own campaigns rather than using the campaign
 * harness: it keeps one campaign per file inside a transaction, and a switch to
 * a second campaign purges the connection and takes that transaction with it
 * (L-10).
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * The campaign a test names, from the central registry.
 */
function campaign(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

test('each campaign keeps its operators in its own database', function (): void {
    $harbor = campaign('harbor-cleanup');
    $ridge = campaign('ridge-restoration');

    tenancy()->initialize($harbor);
    User::factory()->create(['email' => 'organizer@harbor-cleanup.test']);

    tenancy()->end();

    tenancy()->initialize($ridge);
    User::factory()->create(['email' => 'organizer@ridge-restoration.test']);

    // The isolation stated as behaviour rather than as a fact about schemas:
    // one identical query, asked in two campaigns, answers only about the
    // campaign asking. Nothing scopes it — there is no `campaign_id` to filter
    // on, because the other campaign's rows are in another database entirely.
    expect(User::query()->pluck('email')->all())->toBe(['organizer@ridge-restoration.test']);

    tenancy()->end();
    tenancy()->initialize($harbor);

    expect(User::query()->pluck('email')->all())->toBe(['organizer@harbor-cleanup.test']);

    // And the databases really are separate objects, not one database wearing
    // two names.
    expect($harbor->database()->getName())->not->toBe($ridge->database()->getName());
});

test('a campaign cannot reach another campaign database even by name', function (): void {
    $harbor = campaign('harbor-cleanup');
    $ridge = campaign('ridge-restoration');

    tenancy()->initialize($ridge);
    User::factory()->create(['email' => 'organizer@ridge-restoration.test']);

    tenancy()->end();
    tenancy()->initialize($harbor);

    // The strongest form of the guarantee available to us: connected to Harbor
    // Cleanup, name Ridge Restoration's database and its operator table
    // explicitly. The refusal comes from PostgreSQL, not from application code
    // being careful — there is no query to write that would work.
    $qualify = fn (Tenant $campaign): string => sprintf(
        '"%s".public.users',
        str_replace('"', '""', $campaign->database()->getName()),
    );

    // The control, and it is load-bearing: PostgreSQL answers a *misspelled*
    // database name with the very same "cross-database references" refusal, so
    // without this the test would pass just as happily against a database that
    // does not exist — proving nothing about isolation. Naming Harbor Cleanup's
    // own database in exactly this form succeeds, which establishes that the
    // query below is well-formed and that crossing is the only thing rejected.
    expect(DB::connection('tenant')->select('select * from '.$qualify($harbor).' limit 1'))->toBe([]);

    $qualified = $qualify($ridge);

    $refusal = null;

    try {
        DB::connection('tenant')->select('select * from '.$qualified.' limit 1');
    } catch (QueryException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('cross-database references are not implemented')
        // SQLSTATE 0A000 — feature not supported. Asserted alongside the text so
        // a translated or reworded message cannot quietly weaken this test.
        ->and((string) $refusal->getCode())->toBe('0A000');

    // The connection is still usable afterwards, so the refusal is PostgreSQL
    // declining one statement rather than the campaign losing its database.
    expect(User::query()->count())->toBe(0);
});

test('a campaign cannot read the platform registry', function (): void {
    tenancy()->initialize(campaign('harbor-cleanup'));

    // The other direction of the split. Central knows every campaign; a campaign
    // knows nothing of the platform it runs on, and could not enumerate its
    // peers even if it tried, because the registry is not in its database.
    // Queue tables are checked too — those live centrally by the same reasoning,
    // and a campaign that had its own would mean a job could be hidden inside a
    // campaign where no platform worker would ever see it.
    expect(Schema::connection('tenant')->hasTable('tenants'))->toBeFalse()
        ->and(Schema::connection('tenant')->hasTable('domains'))->toBeFalse()
        ->and(Schema::connection('tenant')->hasTable('jobs'))->toBeFalse()
        ->and(Schema::connection('tenant')->hasTable('failed_jobs'))->toBeFalse();
});
