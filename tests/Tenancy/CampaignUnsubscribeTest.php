<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unsubscribing, asked across the boundary that decides whether the mechanism
 * was the right one.
 *
 * **This file is the argument for D-16(a), run rather than stated.** The
 * decision was between a signed URL and a stored per-supporter token, and it
 * turned on what separates one campaign's link from another's. A signature is
 * taken over the platform-wide APP_KEY: an absolute one separates campaigns
 * only by the hostname inside the signed string, and a relative one does not
 * separate them at all -- measured, `signed:relative` validates a link minted
 * in one campaign against another campaign's host, and because supporter ids
 * restart at 1 in every campaign that unsubscribes a different person.
 *
 * A token has no such split, and the reason is the thing under test here: its
 * scope is the campaign's own database. A token presented on the wrong host
 * matches no row for exactly the reason a supporter does not -- the row is
 * somewhere else. That is DEC-1 doing the work rather than a new mechanism.
 *
 * **Two campaigns throughout, never one (L-21).** With a single campaign "the
 * first" and "the only" are indistinguishable, and every value this project has
 * been bitten by five times -- a cached connection, a cached broker, a cached
 * mailer, a rate-limit counter, a lock name -- is invisible by construction.
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

    // The `unsubscribe` limiter is keyed on the caller and deliberately not on
    // the campaign (L-24), so unlike every campaign-scoped limiter in this
    // suite its counter is not reset by provisioning fresh campaigns -- and
    // tests/Campaign/UnsubscribeTest.php spends the whole budget on purpose.
    // Reached through the manager's driver() because that is the untagged store
    // the framework's own limiter was handed; the Cache facade would flush a
    // different object (L-24, L-27).
    app('cache')->driver()->flush();
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Put one supporter on one campaign's list and hand back their token.
 */
function supporterIn(string $slug, string $email): string
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    $supporter = Supporter::factory()->create(['email' => $email]);
    $token = (string) $supporter->fresh()->unsubscribe_token;

    tenancy()->end();

    return $token;
}

/**
 * What one campaign currently thinks of one address.
 */
function statusIn(string $slug, string $email): SubscriptionStatus
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    $status = Supporter::query()->whereEmailMatches($email)->sole()->subscription_status;

    tenancy()->end();

    return $status;
}

test('one campaign\'s link cannot unsubscribe another campaign\'s supporter', function (): void {
    // **The defect this mechanism exists to make impossible**, and the one a
    // relative signature would have permitted outright.
    $harborToken = supporterIn('harbor-cleanup', 'harbor@example.test');
    supporterIn('ridge-restoration', 'ridge@example.test');

    // Harbor's token, presented on Ridge's hostname. It names nobody there.
    $this->post('http://ridge-restoration.test/unsubscribe/'.$harborToken)->assertNotFound();
    $this->get('http://ridge-restoration.test/unsubscribe/'.$harborToken)->assertNotFound();

    // Neither campaign's supporter moved -- the negative alone would be
    // satisfied by a route that refuses everybody.
    expect(statusIn('ridge-restoration', 'ridge@example.test'))->toBe(SubscriptionStatus::Subscribed)
        ->and(statusIn('harbor-cleanup', 'harbor@example.test'))->toBe(SubscriptionStatus::Subscribed);

    // And the same token on its *own* campaign's hostname works, in the same
    // run. Without this the refusals above prove only that the route is broken.
    $this->post('http://harbor-cleanup.test/unsubscribe/'.$harborToken)->assertRedirect();

    expect(statusIn('harbor-cleanup', 'harbor@example.test'))->toBe(SubscriptionStatus::Unsubscribed)
        ->and(statusIn('ridge-restoration', 'ridge@example.test'))->toBe(SubscriptionStatus::Subscribed);
});

test('one person on two campaigns\' lists leaves one without leaving the other', function (): void {
    // The ordinary case once supporters live per campaign rather than in one
    // shared table: one human being, one address, two campaigns, two rows, two
    // tokens. Asking one campaign to stop says nothing about the other, and a
    // pooled table would make it say everything.
    $harborToken = supporterIn('harbor-cleanup', 'both@example.test');
    $ridgeToken = supporterIn('ridge-restoration', 'both@example.test');

    expect($harborToken)->not->toBe($ridgeToken);

    $this->post('http://harbor-cleanup.test/unsubscribe/'.$harborToken)->assertRedirect();

    expect(statusIn('harbor-cleanup', 'both@example.test'))->toBe(SubscriptionStatus::Unsubscribed)
        ->and(statusIn('ridge-restoration', 'both@example.test'))->toBe(SubscriptionStatus::Subscribed);

    // Then the other way round, which is what makes this two campaigns rather
    // than one campaign and a control.
    $this->post('http://ridge-restoration.test/unsubscribe/'.$ridgeToken)->assertRedirect();

    expect(statusIn('ridge-restoration', 'both@example.test'))->toBe(SubscriptionStatus::Unsubscribed);
});

test('the page names the campaign whose list it is, and not the first campaign to ask', function (): void {
    // The L-21 family aimed at this step's own new value. A campaign name read
    // once and served to every campaign after would tell a Ridge supporter they
    // were leaving Harbor's list -- which reads as a cosmetic defect and is
    // not: it is the only thing on the page that says whose mail this was.
    // Asked in Ridge *second*, deliberately: a value captured once is captured
    // by whichever campaign ran first, so the second is where it shows.
    $harborToken = supporterIn('harbor-cleanup', 'harbor@example.test');
    $ridgeToken = supporterIn('ridge-restoration', 'ridge@example.test');

    $this->get('http://harbor-cleanup.test/unsubscribe/'.$harborToken)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('campaignName', 'Harbor Cleanup')
            ->where('email', 'harbor@example.test'));

    $this->get('http://ridge-restoration.test/unsubscribe/'.$ridgeToken)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('campaignName', 'Ridge Restoration')
            ->where('email', 'ridge@example.test'));
});

test('the tokens live in each campaign\'s own database rather than in a shared table', function (): void {
    // The configuration invariant behind every behaviour above (L-14's
    // pairing). The refusals would all still hold if a shared table happened to
    // hold no colliding row today; this says there is no shared table to hold
    // one.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    supporterIn('harbor-cleanup', 'harbor@example.test');

    tenancy()->initialize($harbor);
    $harborDatabase = DB::connection()->getDatabaseName();
    $harborRows = Supporter::query()->count();
    tenancy()->end();

    tenancy()->initialize($ridge);
    $ridgeDatabase = DB::connection()->getDatabaseName();
    $ridgeRows = Supporter::query()->count();
    tenancy()->end();

    expect($harborDatabase)->toBe($harbor->database()->getName())
        ->and($ridgeDatabase)->toBe($ridge->database()->getName())
        ->and($harborDatabase)->not->toBe($ridgeDatabase)
        // Harbor's one supporter is invisible to Ridge, which is what makes the
        // 404 above a boundary rather than a coincidence.
        ->and($harborRows)->toBe(1)
        ->and($ridgeRows)->toBe(0);

    // And no supporter list centrally, which is where a shared table would be.
    $central = (string) config('tenancy.database.central_connection');

    expect(Schema::connection($central)->hasTable('supporters'))->toBeFalse();
});
