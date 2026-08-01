<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The unsubscribe page, opened in a real browser by nobody at all.
 *
 * **Exit criterion 5, and the browser half is not optional for it.** Two defect
 * classes live on this page and the server is structurally unable to report
 * either one.
 *
 * **The first is L-12, and this page is the exact shape it was catalogued for.**
 * `resources/js/app.ts` picks a layout by page name in a `switch (true)` whose
 * default arm is AppLayout -- the signed-in application shell, with a sidebar,
 * a campaign navigation and an account menu that reads `$page.props.auth.user`.
 * A page whose name the resolver does not know falls into that arm and renders
 * that shell to a stranger who by definition has no session, **while the route
 * still answers 200 with the right component name**. Every server-side
 * assertion in tests/Campaign/UnsubscribeTest.php passes either way, and that
 * file says so in its own docblock rather than pretending otherwise. Only
 * opening the page can tell.
 *
 * **The second is the form's action, which is generated on the client.** The
 * Unsubscribe button posts through `UnsubscribeController.store.form(token)` --
 * a Wayfinder action built in the browser from a prop. The campaign tests POST
 * the URL by hand, so they would stay green against a control wired to the
 * wrong route, to the wrong verb, or to no token at all. Clicking it is the
 * only thing that exercises what a supporter actually triggers.
 *
 * **No operator is signed in, and that is the subject rather than a shortcut.**
 * Every other campaign browser file begins by enrolling somebody; this page's
 * whole claim is that it works for a person who has no account and never will.
 *
 * The campaign is provisioned because the page is served on a campaign
 * hostname, and 127.0.0.1 has to *be* that campaign before the browser can
 * reach it -- Tests\Support\LoopbackHost's job, and this is the fifth file to
 * claim the address, so it is also the fifth exercise of the
 * release-before-claim fix.
 */

uses(RunsInCampaignContext::class);

beforeEach(function (): void {
    $this->enterCampaignContext();

    LoopbackHost::claimFor($this->campaign);

    // The `unsubscribe` limiter is keyed on the caller and deliberately not on
    // the campaign (L-24), so its budget is platform-wide and carries across
    // files in one process -- and tests/Campaign/UnsubscribeTest.php spends it
    // in full on purpose. Reached through the manager's driver() because that
    // is the untagged store the framework's own limiter holds.
    app('cache')->driver()->flush();
});

afterEach(function (): void {
    LoopbackHost::release();

    $this->leaveCampaignContext();
});

test('a supporter with no account opens the page and leaves the list, outside the application shell', function (): void {
    $supporter = Supporter::factory()->create(['email' => 'ama.boateng@example.test']);

    $page = visit('/unsubscribe/'.$supporter->fresh()->unsubscribe_token);

    $page
        // **First, that Vue mounted at all.** Every assertion below is evidence
        // only because of this one: an empty `<div id="app">` satisfies both of
        // the "not the app shell" claims perfectly, which is exactly how this
        // test would otherwise be green for nothing.
        ->assertSee('Stop receiving email from')

        // The server's data reached the client, so the page is rendering this
        // campaign's supporter rather than a shell with nothing in it.
        ->assertSee('ama.boateng@example.test')
        ->assertSee($this->campaign->name)
        ->assertPresent('[data-test="unsubscribe-confirm"]')

        // **The L-12 claim, negative half -- and the one assertion in this file
        // that could not be broken from the server. Its evidence is stated
        // here rather than assumed.**
        //
        // The obvious break is to make the page land in the default arm, and it
        // is not constructible without a build: Inertia resolves the component
        // *from the same name* the layout resolver switches on, so a name the
        // resolver does not know is also a different component. Tried anyway,
        // by rendering `Dashboard` from this route -- the page then wears the
        // shell as predicted, but the mount assertion above fails first and
        // this line is never reached. That is the pre-empted-absence family
        // again: the break fires a louder failure upstream of the assertion it
        // was meant to test.
        //
        // What supports it instead is two things that are checkable. The
        // selector engine demonstrably resolves `data-test` attributes on this
        // exact page, because `unsubscribe-confirm` is asserted *present* three
        // lines up -- so this is not silently passing on a selector the plugin
        // failed to parse, which is the trap it makes easy, since a selector it
        // does not recognise as CSS becomes a text search. And the element
        // itself is demonstrably findable by this suite, because four other
        // files assert this same selector *present* on pages that do wear the
        // shell (SupporterList, SupporterEdit, BlastList, BlastCompose).
        //
        // The resolver arm was additionally confirmed in the *compiled* bundle
        // rather than only in source, which is what the build step makes
        // necessary: `case e===\`Unsubscribe\`:return null` is in app-*.js.
        ->assertMissing('[data-test="sidebar-menu-button"]')

        // The sidebar's campaign navigation, absent for the same reason and
        // asserted separately because it fails one step earlier: the nav items
        // render before anything reads the operator, so a shell that renders
        // only partially still shows these.
        ->assertDontSee('Supporters')
        ->assertDontSee('Dashboard')

        ->assertNoJavaScriptErrors();

    // **Now the second defect class: the control is clicked rather than
    // simulated.** The form's action is built in the browser by Wayfinder from
    // the token prop, so a control pointing at the wrong route or carrying no
    // token would still leave every server-side test green.
    $page->click('Unsubscribe');

    $page
        ->assertPresent('[data-test="unsubscribe-done"]')
        ->assertSee('You have been unsubscribed')
        // The confirm control is gone, because the page's content is a function
        // of the supporter's status rather than of which request produced it.
        ->assertMissing('[data-test="unsubscribe-confirm"]')
        // Still not the application shell after a client-side visit, which is a
        // second chance for the resolver to put it there.
        ->assertMissing('[data-test="sidebar-menu-button"]')
        ->assertNoJavaScriptErrors();

    // And the row really moved, so the page above is reporting a write rather
    // than a hopeful message. This is what makes the click a test of the wiring
    // instead of a test of the markup.
    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed);
});
