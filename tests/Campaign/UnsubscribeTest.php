<?php

declare(strict_types=1);

use App\Blasts\BlastAudience;
use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Supporter;
use App\Models\Unsubscribe;
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

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Subscribed)
        // And nothing is recorded as an outcome either: a GET is evidence of a
        // scanner as readily as of a person, which is why D-45 took only the
        // POST.
        ->and(Unsubscribe::query()->count())->toBe(0);
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
        ->and($writes)->toBeEmpty()
        // One withdrawal, not two: the repeat changed nothing, so it is not a
        // second act by the person (D-47).
        ->and(Unsubscribe::query()->count())->toBe(1);
});

test('leaving records one withdrawal, and credits it to no message', function (): void {
    // **A link mailed before messages named themselves carries the supporter's
    // token**, which names a person and no message. Those links keep working,
    // because that is what D-16 promised the person holding one, and the
    // withdrawal is recorded as unattributed -- never credited to the latest
    // blast they were sent, which is the one mistake D-46 says no later change
    // can repair. They were sent two, so a writer choosing one would have one
    // to choose.
    $supporter = Supporter::factory()->create();

    BlastRecipient::factory()->forSupporter($supporter)->sent()->create();
    BlastRecipient::factory()->forSupporter($supporter)->sent()->create();

    $this->post($this->campaignUrl('unsubscribe/'.$supporter->fresh()->unsubscribe_token))->assertRedirect();

    expect(Unsubscribe::query()->pluck('blast_recipient_id')->all())->toBe([null])
        ->and(Unsubscribe::query()->sole()->created_at)->not->toBeNull();
});

test('a withdrawal through a message\'s own link names that message, and not the latest', function (): void {
    // **§7 criterion 2, in the direction that is easiest to get wrong.** This
    // supporter was sent two blasts; the link they used is the first one's.
    // Crediting the withdrawal to the blast that happens to be most recent
    // would be telling a campaign that a message caused something it did not,
    // which is the failure D-46 says no later change repairs.
    $supporter = Supporter::factory()->create();

    $first = BlastRecipient::factory()->forSupporter($supporter)->sent()->create();
    $latest = BlastRecipient::factory()->forSupporter($supporter)->sent()->create();

    $token = DB::connection('tenant')->table('blast_recipients')->where('id', $first->getKey())->value('link_token');

    // The page answers to this credential as readily as to the supporter's
    // own, and says whose address it is about, because the copy knows its
    // reader.
    $this->get($this->campaignUrl('unsubscribe/'.$token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Unsubscribe')->where('email', $supporter->email));

    $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect();

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed)
        ->and(Unsubscribe::query()->pluck('blast_recipient_id')->all())->toBe([$first->getKey()])
        // Named rather than left implicit: the row the writer must not have
        // chosen is the newer one, and it exists.
        ->and($latest->getKey())->toBeGreaterThan($first->getKey());
});

test('a link whose reader has been erased resolves to nobody', function (): void {
    // **D-46's condition, which the schema and the resolver answer
    // separately.** A recipient row outlives the person: an erasure nulls its
    // key and keeps the row, so a lookup by token alone would find a message
    // belonging to somebody who no longer exists. The column's trigger removes
    // the token as the key goes, which makes that state unreachable -- and
    // that is exactly why this test disables the trigger. What is under test
    // here is the resolver's own refusal, which must hold whether or not the
    // schema is also holding; a guard that depended on the trigger would be
    // reporting the trigger twice and the join never.
    DB::connection('tenant')->statement('alter table "blast_recipients" disable trigger "blast_recipients_forget_link_token"');

    $supporter = Supporter::factory()->create();
    $recipient = BlastRecipient::factory()->forSupporter($supporter)->sent()->create();
    $token = DB::connection('tenant')->table('blast_recipients')->where('id', $recipient->getKey())->value('link_token');

    $supporter->delete();

    // The row and its token really did survive, so the 404 below is the
    // resolver refusing rather than there being nothing to find.
    expect(DB::connection('tenant')->table('blast_recipients')->where('link_token', $token)->count())->toBe(1);

    $this->get($this->campaignUrl('unsubscribe/'.$token))->assertNotFound();
    $this->post($this->campaignUrl('unsubscribe/'.$token))->assertNotFound();

    expect(Unsubscribe::query()->count())->toBe(0);
});

test('leaving again after an operator put them back is a second withdrawal', function (): void {
    // One link, two real changes of status, measured at Step 2 (D-47): an
    // operator's re-subscription is not evidence the person changed their
    // mind, so the link used afterwards records what it did again.
    $supporter = Supporter::factory()->create();
    $token = $supporter->fresh()->unsubscribe_token;

    $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect();

    $supporter->fresh()->forceFill(['subscription_status' => SubscriptionStatus::Subscribed])->save();

    $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect();

    expect(Unsubscribe::query()->count())->toBe(2)
        ->and($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed);
});

test('six messages to one person, each link used once, are one withdrawal and not six', function (): void {
    // **This is the complement of the test above, and between them they fix
    // what this table counts.** That one says an operator's re-subscription
    // makes the next use of the link a second withdrawal. This one says the
    // number of *messages* does not: somebody who leaves has left, and the
    // five later links they click are asking for the state they already hold.
    //
    // **It is here because the quantity `unsubscribes` grows with is the whole
    // of Phase 5 Step 5's answer, and nothing pinned it.** Both of the tests
    // that look as though they cover this use the supporter's own token, so the
    // copy the writer resolves is null in each and neither can see a writer
    // that deduplicates per copy instead of per act. Measured: rewritten that
    // way -- one row per recipient row that has not yet been recorded -- the
    // whole suite outside Browser stayed green at 690 of 690, while this
    // campaign's table grew with recipients x blasts instead of with the number
    // of people who have left.
    //
    // **What that would cost if it went unnoticed**, which is why the guard is
    // worth its place rather than being a restatement of the writer: counted
    // per act, this table's ceiling is the supporter list, so at 75.0 bytes a
    // row a quarter-million-supporter campaign tops out at 18.8 MB however long
    // it runs. Counted per copy it would grow with every send instead, without
    // limit -- a different table with the same name, arriving silently.
    $supporter = Supporter::factory()->create();

    $tokens = collect(range(1, 6))->map(function () use ($supporter): string {
        $copy = BlastRecipient::factory()
            ->ofBlast(Blast::factory()->sent()->create())
            ->forSupporter($supporter)
            ->sent()
            ->create();

        return (string) DB::connection('tenant')->table('blast_recipients')
            ->where('id', $copy->getKey())->value('link_token');
    });

    $tokens->each(fn (string $token) => $this->post($this->campaignUrl('unsubscribe/'.$token))->assertRedirect());

    // Six copies really were made and really did carry six different links, so
    // the one row below is the writer's doing rather than the fixture's. A
    // fixture that quietly produced one copy would satisfy the assertion that
    // matters while testing nothing, which is the failure this phase has made
    // three times.
    expect(BlastRecipient::query()->count())->toBe(6)
        ->and($tokens->unique()->count())->toBe(6)
        ->and(Unsubscribe::query()->count())->toBe(1)
        ->and($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed);

    // And the one row names the message whose link was used first, which is the
    // only message that can honestly claim it: the five that followed asked
    // somebody who had already gone.
    expect(Unsubscribe::query()->sole()->blast_recipient_id)
        ->toBe(BlastRecipient::query()->orderBy('id')->value('id'));
});

test('somebody unsubscribed before withdrawals were recorded gains no record by using the link', function (): void {
    // **The §3 convention: nothing is back-filled.** The demo campaign's own
    // shape -- a supporter already unsubscribed with no row saying when or
    // why. Their link still answers, and asks for the state already held, so
    // it records nothing; a row here would date an act to a request that did
    // not perform it.
    $supporter = Supporter::factory()->create(['subscription_status' => SubscriptionStatus::Unsubscribed]);

    $this->post($this->campaignUrl('unsubscribe/'.$supporter->fresh()->unsubscribe_token))->assertRedirect();

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed)
        ->and(Unsubscribe::query()->count())->toBe(0);
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
