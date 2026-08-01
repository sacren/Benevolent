<?php

declare(strict_types=1);

use App\Blasts\BlastAudience;
use App\Models\Blast;
use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Leaving a campaign's list, from a link in a message.
 *
 * The first surface this product serves to somebody with no account, no session
 * and no operator behind them, so the property most of this file is about is
 * one no other page-rendering route in this application has: it answers at all.
 *
 * **The limiter is reset between tests, and the reason is worth stating because
 * it is a consequence of a deliberate design choice.** `unsubscribe` is keyed
 * on the caller's address and *not* on the campaign (L-24), which is correct --
 * one caller is one caller wherever they knock -- and it means the counter does
 * not reset when a test provisions a fresh campaign, the way every
 * campaign-scoped limiter in this suite does by accident. Pest runs a file in
 * one process, so without this the twentieth request in the file would be
 * throttled and every test after it would fail for a reason none of them names.
 * The last test in this file spends the budget on purpose.
 */
beforeEach(function (): void {
    // The store the framework's own limiter holds. Reached through the manager's
    // driver() rather than the Cache facade deliberately: driver() is a real
    // method on the manager, so it escapes the tenancy package's __call-based
    // tagging and returns the same untagged store CacheServiceProvider handed
    // the limiter (L-24, L-27). Going through the facade would flush a
    // different object and leave the counters exactly where they were.
    app('cache')->driver()->flush();
});

test('somebody with no account at all can open the page', function (): void {
    // **The property no other page-rendering route in this application has.**
    // Every one of them sits behind `auth` and `verified`; the single prior
    // exception returns JSON and renders nothing.
    $supporter = Supporter::factory()->create(['email' => 'reader@example.test']);

    $this->assertGuest();

    $this->get($this->campaignUrl('unsubscribe/'.$supporter->fresh()->unsubscribe_token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Unsubscribe')
            ->where('email', 'reader@example.test')
            ->where('unsubscribed', false));

    // Still nobody, so the page really was served to an anonymous visitor
    // rather than to a session something quietly established.
    $this->assertGuest();
});

test('the route is wired without auth and with a uuid constraint, which is what the request above depends on', function (): void {
    // The configuration invariant behind the behaviour (L-14's pairing). The
    // test above would pass just as happily against a route that *did* require
    // authentication if the harness had signed somebody in -- this cannot.
    $show = Route::getRoutes()->getByName('unsubscribe.show');
    $store = Route::getRoutes()->getByName('unsubscribe.store');

    expect($show)->not->toBeNull()
        ->and($store)->not->toBeNull();

    foreach ([$show, $store] as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->not->toContain('auth')
            ->and($middleware)->not->toContain('verified')
            // Inside `tenant`, because the campaign is what identifies the
            // supporter -- the same person on two lists is two rows in two
            // databases and the Host header is what says which.
            ->and($middleware)->toContain('tenant')
            ->and($middleware)->toContain('throttle:unsubscribe');

        // Without this the column's type turns a mistyped link into SQLSTATE
        // 22P02 -- a 500 for a member of the public, with the offending value
        // inlined into the exception message.
        expect($route->wheres)->toHaveKey('token');
    }
});

test('a supporter unsubscribes themselves, and the page then says so', function (): void {
    $supporter = Supporter::factory()->create(['email' => 'leaving@example.test']);
    $token = $supporter->fresh()->unsubscribe_token;

    // Post-redirect-get, so a refresh re-issues the GET rather than
    // re-submitting an act the page said could not be undone.
    $response = $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect();

    // The path and the token rather than the whole URL: the generated location
    // also carries the port from APP_URL, because CampaignHostTenancyBootstrapper
    // forces the root onto this campaign's own hostname. Asserting the full
    // string would restate that formula back at itself and would go red on a
    // deployment with no port rather than on anything being wrong.
    expect($response->headers->get('Location'))->toContain('/unsubscribe/'.$token);

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed);

    // And the same URL now answers with the other state, because the page's
    // content is a function of the supporter's status rather than of which
    // request produced it.
    $this->get($this->campaignUrl('unsubscribe/'.$token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Unsubscribe')->where('unsubscribed', true));
});

test('a link scanner following the link does not unsubscribe anybody', function (): void {
    // **D-16(b), stated as the failure it prevents rather than as a preference
    // for REST.** Mail providers' link scanners and client prefetchers issue
    // GET requests against every URL in a message, with nobody clicking
    // anything. A GET that mutated would unsubscribe people from mail they
    // wanted, on delivery.
    $supporter = Supporter::factory()->create();
    $token = $supporter->fresh()->unsubscribe_token;

    $this->get($this->campaignUrl('unsubscribe/'.$token))->assertOk();
    $this->get($this->campaignUrl('unsubscribe/'.$token))->assertOk();

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Subscribed);
});

test('following the link twice is not an error and does not rewrite the record', function (): void {
    $supporter = Supporter::factory()->create();
    $token = $supporter->fresh()->unsubscribe_token;

    $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect();

    // **The second request is watched rather than inferred from, and the first
    // attempt at this assertion is why.** It compared `updated_at` before and
    // after, which reads as careful and could not fail: the column casts at
    // second resolution, both requests land in the same second, and the two
    // values are identical whether or not anything was written. That is L-23's
    // shape in a guard of this step's own making. Counting the statements
    // measures the thing directly and has no arithmetic to be defeated by.
    //
    // **Two mutations were needed to establish that, and the first one lied.**
    // Adding a `touch()` leaves this green -- not because the guard is weak but
    // because `touch()` sets `updated_at` to a value it already holds within
    // the same second, so Eloquent finds the model clean and issues nothing
    // either. The break that reddens it is the realistic alternative
    // implementation: a query-builder `update()`, which writes unconditionally
    // because there is no model to be clean. Recorded because a mutation coming
    // back green is not by itself evidence about the guard.
    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect();

    $writes = collect(DB::connection('tenant')->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'update "supporters"'));

    DB::connection('tenant')->disableQueryLog();

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed)
        // The state asked for is the state already held, so nothing is written
        // -- otherwise the record would say somebody asked twice, on two
        // different days, which is not what happened.
        //
        // **This guards the framework rather than our own code, deliberately.**
        // The controller writes unconditionally; what makes the repeat free is
        // Eloquent issuing no statement for a model whose attributes have not
        // changed. An explicit status comparison in the controller reddened
        // nothing and was removed rather than kept as a comment with syntax.
        ->and($writes)->toBeEmpty();
});

test('a blast sent afterwards does not reach them', function (): void {
    // The definition of done, end to end: this is the whole point of the step
    // and it is asserted through the audience the send actually walks rather
    // than through the column.
    $staying = Supporter::factory()->create(['email' => 'staying@example.test']);
    $leaving = Supporter::factory()->create(['email' => 'leaving@example.test']);

    $blast = Blast::factory()->create();

    expect(BlastAudience::for($blast)->pluck('email')->all())
        ->toEqualCanonicalizing(['staying@example.test', 'leaving@example.test']);

    $this->post($this->campaignUrl('unsubscribe/'.$leaving->fresh()->unsubscribe_token))->assertRedirect();

    expect(BlastAudience::for($blast)->pluck('email')->all())->toBe(['staying@example.test'])
        ->and(BlastAudience::size($blast))->toBe(1)
        ->and($staying->fresh()->subscription_status)->toBe(SubscriptionStatus::Subscribed);
});

test('a token nobody holds is a 404 rather than an answer', function (): void {
    // Well-formed and unknown: a stranger guessing, a link belonging to another
    // campaign, or a supporter who has since been erased. All three are the
    // same answer, and it is the honest one -- there is no row, so there is
    // nobody to unsubscribe and nothing to say about who used to be there.
    $unknown = '11111111-2222-4333-8444-555555555555';

    $this->get($this->campaignUrl('unsubscribe/'.$unknown))->assertNotFound();
    $this->post($this->campaignUrl('unsubscribe/'.$unknown))->assertNotFound();
});

test('a malformed token is a 404 rather than a 500', function (): void {
    // **The sharp edge of a `uuid` column, measured rather than feared.** A
    // lookup for a non-uuid against that column raises SQLSTATE 22P02 instead
    // of matching no rows, and the exception message inlines the offending
    // value -- which would reach a member of the public as a 500 and reach the
    // log as the string they typed. The route's constraint answers first, so no
    // query is ever built.
    foreach (['not-a-uuid', 'zzzzzzzz-2222-4333-8444-555555555555', '12345'] as $malformed) {
        $this->get($this->campaignUrl('unsubscribe/'.$malformed))->assertNotFound();
        $this->post($this->campaignUrl('unsubscribe/'.$malformed))->assertNotFound();
    }
});

test('the page a supporter lands on is never the signed-in application shell', function (): void {
    // The server half of L-12, and it is deliberately the weaker half: the
    // route answers 200 with the right component name whichever shell the
    // client picks, so this cannot see the defect at all. What it *can* pin is
    // that the page carries no operator vocabulary for a shell to read.
    // tests/Browser/UnsubscribePageTest.php is what actually opens it.
    $supporter = Supporter::factory()->create();

    $this->get($this->campaignUrl('unsubscribe/'.$supporter->fresh()->unsubscribe_token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Unsubscribe')
            // permissionsFor() returns [] for anything that is not a User, and
            // an anonymous visitor is exactly that.
            ->where('auth.user', null)
            ->where('auth.permissions', []));
});

test('the endpoint is metered, and the budget can actually be crossed', function (): void {
    // **L-23's discipline: a guard that works by exhausting something must
    // spend enough to cross the threshold it names.** Twenty a minute, so the
    // twenty-first is the first that can be refused -- and an unknown token is
    // used because the throttle runs before the controller, which makes each
    // request cheap and makes this the enumeration case the limit exists for.
    $unknown = '11111111-2222-4333-8444-555555555555';

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->get($this->campaignUrl('unsubscribe/'.$unknown))->assertNotFound();
    }

    $this->get($this->campaignUrl('unsubscribe/'.$unknown))->assertStatus(429);
});
