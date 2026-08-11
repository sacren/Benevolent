<?php

declare(strict_types=1);

use App\Blasts\BlastAudience;
use App\Models\Blast;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The audience of a blast, computed in two campaigns at once.
 *
 * CampaignBlastIsolationTest proves a campaign keeps the messages it writes in
 * its own database. This proves the harder half, which is the one a send acts
 * on: that working out *who a message goes to* answers about the campaign
 * asking and nobody else. A leak here is not a reader seeing the wrong row --
 * it is a campaign's message arriving in another campaign's supporters'
 * inboxes, which is the worst thing this application could do.
 *
 * **Two campaigns rather than one, which is the whole point (L-21).** With a
 * single campaign, "the first" and "the only" are indistinguishable, and every
 * shape this project has been bitten by five times -- a cached connection, a
 * cached broker, a cached mailer, a rate-limit counter, a lock name -- is
 * invisible by construction. So both campaigns hold supporters whose postcodes
 * are *deliberately in the same area*: a prefix that is correct for one
 * campaign matches rows in the other, and only the database boundary keeps them
 * apart. A shared table would answer this test wrongly rather than crash.
 *
 * These tests provision their own campaigns rather than using the campaign
 * harness, which keeps one campaign per file inside a transaction that a switch
 * to a second campaign would purge (L-10).
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php -- CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('an audience worked out in one campaign cannot see another campaign\'s supporters', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The same postcode area in both campaigns, written the two ways a real
    // list writes it. Nothing distinguishes these rows except which database
    // they are in, so the prefix below is correct for either campaign.
    tenancy()->initialize($harbor);
    Supporter::factory()->create(['email' => 'harbor-one@example.test', 'postcode' => '90210']);
    Supporter::factory()->create(['email' => 'harbor-two@example.test', 'postcode' => '90210 1234']);

    tenancy()->end();
    tenancy()->initialize($ridge);
    Supporter::factory()->create(['email' => 'ridge-one@example.test', 'postcode' => '90210']);

    // Asked in Ridge first, deliberately: a value captured once and served to
    // every campaign after is captured by whichever campaign ran first, so the
    // second campaign is where that defect shows.
    $ridgeBlast = Blast::factory()->narrowedToPostcodes(['902'])->create();

    expect(BlastAudience::for($ridgeBlast)->pluck('email')->all())->toBe(['ridge-one@example.test'])
        ->and(BlastAudience::size($ridgeBlast))->toBe(1);

    tenancy()->end();
    tenancy()->initialize($harbor);

    $harborBlast = Blast::factory()->narrowedToPostcodes(['902'])->create();

    expect(BlastAudience::for($harborBlast)->pluck('email')->all())
        ->toEqualCanonicalizing(['harbor-one@example.test', 'harbor-two@example.test'])
        ->and(BlastAudience::size($harborBlast))->toBe(2);

    // And the supporters really are in the campaign's own database rather than
    // in a shared one each campaign reads a slice of -- the configuration
    // invariant behind the behaviour above (L-14), so neither half can stand
    // alone.
    expect(DB::connection()->getDatabaseName())->toBe($harbor->database()->getName())
        ->and($harbor->database()->getName())->not->toBe($ridge->database()->getName());
});

test('unsubscribing in one campaign does not withdraw the same person from another', function (): void {
    // The status half of the audience, asked across the boundary. One person,
    // one address, on two campaigns' lists -- which is ordinary, because a
    // supporter is a row in a campaign's own database rather than a platform
    // account. Asking one campaign not to write to you says nothing about the
    // other, and a pooled table would make it say everything.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    tenancy()->initialize($harbor);
    Supporter::factory()->create([
        'email' => 'both@example.test',
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    tenancy()->end();
    tenancy()->initialize($ridge);
    Supporter::factory()->create([
        'email' => 'both@example.test',
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);

    $ridgeBlast = Blast::factory()->create();

    expect(BlastAudience::for($ridgeBlast)->pluck('email')->all())->toBe(['both@example.test']);

    tenancy()->end();
    tenancy()->initialize($harbor);

    $harborBlast = Blast::factory()->create();

    expect(BlastAudience::size($harborBlast))->toBe(0);
});
