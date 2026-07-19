<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The DEC-1 isolation guarantee, restated for the first product data on this
 * platform.
 *
 * CampaignIsolationTest proves a campaign cannot read another campaign's
 * operators. This proves the same thing for the people a campaign is trying to
 * reach — the data that actually belongs to members of the public, and the one
 * a reader would most obviously assume is platform-wide. Nothing enforces it
 * separately: it falls out of `App\Models\Supporter` naming no connection, so
 * the model follows the default one tenancy has switched onto the campaign
 * serving the request.
 *
 * **What this file is worth was measured, and the first answer given for it was
 * wrong.** It was justified as the guard against one word added to a model, and
 * it is not: removing this file and rebuilding that defect the way someone who
 * believed supporters were platform data would actually ship it — the model
 * naming the central connection, with the migration filed into the central set
 * so there is a table for it to reach — leaves **seven other tests** red. This
 * is not the last line of defence against a pooled list and should not be read
 * as one.
 *
 * Three things it does carry alone. Only the second test below states that
 * supporter identity is **campaign-local**; a platform-wide account would be a
 * design error rather than a crash, so all seven of those stay green against
 * it. This is the only place in the suite where **two campaigns hold
 * supporters at once**, and with one campaign a leak is invisible by
 * construction (L-21) — the shape that catches a connection cached across
 * campaigns, which this project has met three times. And it reports the fault
 * *as a leak*, naming the campaign that read another's supporter, where the
 * others report a missing relation: the difference between debugging a
 * migration and seeing a disclosure.
 *
 * These tests provision their own campaigns rather than using the campaign
 * harness, which keeps one campaign per file inside a transaction that a switch
 * to a second campaign would purge (L-10).
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

test('each campaign keeps its supporters in its own database', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    tenancy()->initialize($harbor);
    Supporter::factory()->create(['email' => 'signer@harbor-cleanup.test']);

    tenancy()->end();

    tenancy()->initialize($ridge);
    Supporter::factory()->create(['email' => 'signer@ridge-restoration.test']);

    // The isolation stated as behaviour rather than as a fact about schemas: one
    // identical query, asked in two campaigns, answers only about the campaign
    // asking. Nothing scopes it -- there is no campaign_id to filter on, because
    // the other campaign's supporters are in another database entirely.
    expect(Supporter::query()->pluck('email')->all())->toBe(['signer@ridge-restoration.test']);

    tenancy()->end();
    tenancy()->initialize($harbor);

    expect(Supporter::query()->pluck('email')->all())->toBe(['signer@harbor-cleanup.test']);

    // And the list really is in the campaign's own database rather than in a
    // shared one both campaigns happen to be reading a slice of.
    expect(DB::connection()->getDatabaseName())->toBe($harbor->database()->getName())
        ->and($harbor->database()->getName())->not->toBe($ridge->database()->getName());
});

test('one person may support two campaigns, because identity is campaign-local', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The other reading of D-8, and the one that would be wrong. An address
    // identifies a supporter *within* a campaign; it is not a platform-wide
    // account. Someone who cares about the harbor and about the ridge appears on
    // both lists, and each campaign owns its own record of them -- including
    // whether they have asked that campaign not to write to them.
    //
    // This is also what makes the unique index correct rather than merely
    // strict: it is unique per table, and a table is one campaign's, so nothing
    // has to remember to scope it. Were the list ever pooled centrally, this
    // test would go red before the isolation one did, because the second
    // campaign's insert would be refused as a duplicate.
    tenancy()->initialize($harbor);
    Supporter::factory()->create(['email' => 'both@example.test', 'name' => 'Harbor Volunteer']);

    tenancy()->end();
    tenancy()->initialize($ridge);
    Supporter::factory()->unsubscribed()->create(['email' => 'both@example.test', 'name' => 'Ridge Volunteer']);

    expect(Supporter::query()->sole()->name)->toBe('Ridge Volunteer');

    tenancy()->end();
    tenancy()->initialize($harbor);

    expect(Supporter::query()->sole()->name)->toBe('Harbor Volunteer');
});
