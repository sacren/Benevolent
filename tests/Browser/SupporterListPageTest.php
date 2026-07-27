<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\User;
use Tests\Concerns\RunsInCampaignContext;

/*
 * The supporter list, opened in a real browser by a signed-in Owner.
 *
 * This file exists for one contrast the server cannot see. The page carries two
 * controls that must be *different kinds of link, for opposite reasons*:
 *
 *   - "Export the list" must be a plain <a>. An Inertia <Link> there issues an
 *     XHR expecting a JSON page object and is handed a CSV.
 *   - "Previous"/"Next" must be Inertia <Link>s. A plain <a> there would work,
 *     visibly -- and would throw away the SPA visit for a full document load.
 *
 * Both requirements are, today, recorded only in comments beside the markup.
 * Nothing reads a comment. Both controls render identically in the DOM, as an
 * <a href>, so no source assertion can separate them either: the difference is
 * entirely in what happens when a person clicks. Hence a browser.
 *
 * Reaching this page at all is the other half of the work. The browser's HTTP
 * server binds to a hardcoded 127.0.0.1 and rewrites every visit onto it, with
 * no injection point -- so a campaign page is unreachable until 127.0.0.1
 * *is* the campaign, which is what the two arrangements in beforeEach do.
 */

uses(RunsInCampaignContext::class);

beforeEach(function (): void {
    $this->enterCampaignContext();

    // The browser can only ever ask for 127.0.0.1, so the campaign has to
    // answer to it. A second domain row rather than a replacement: the
    // harness's own `<slug>.test` row stays, so `campaignUrl()` and anything
    // else reading the campaign's hostname keep working.
    $this->campaign->createDomain(['domain' => '127.0.0.1']);

    // And central has to stop claiming it. PreventAccessFromCentralDomains runs
    // ahead of tenant resolution and redirects any central host away from
    // campaign routes, so while 127.0.0.1 is in this list the browser is sent
    // to /campaign-sign-in no matter what it asks for.
    //
    // Config only, for the life of this test. The committed default in
    // config/tenancy.php is untouched, which matters: that list is a security
    // boundary and this must not be a way of quietly widening it.
    config([
        'tenancy.central_domains' => array_values(array_diff(
            (array) config('tenancy.central_domains'),
            ['127.0.0.1'],
        )),
    ]);
});

afterEach(function (): void {
    $this->leaveCampaignContext();
});

test('an owner sees the list, and its two controls behave as different kinds of link', function (): void {
    // One more than a page, which is the smallest list that makes the paging
    // strip exist at all -- it renders only when there is more than one page.
    //
    // SupporterController::PER_PAGE is private, so this mirrors it rather than
    // reading it, and the coupling is stated here because it is silent: raising
    // the page size leaves this test green while it quietly stops exercising
    // paging, having become a single-page list with no strip to click. The
    // count assertion below is what would notice.
    $perPage = 50;

    $first = Supporter::factory()->create(['email' => 'first-arrival@example.test']);
    Supporter::factory()->count($perPage)->create();

    $this->actingAs(User::factory()->owner()->create());

    $page = visit('/supporters');

    $page
        // Vue mounted and the server's data reached it. Everything below is
        // evidence only because of this: an empty page would satisfy every
        // "this control is absent" claim perfectly.
        ->assertSee('51 people on this campaign’s list')

        // The table body, written with a combinator so that the plugin reads it
        // as CSS at all. A bare `table` is not recognised as a selector and
        // degrades silently to a search for the *word* "table" -- which fails
        // loudly here, but would pass vacuously in any assertMissing.
        ->assertPresent('table > tbody')
        ->assertPresent('nav[aria-label="Supporter list pages"]')

        // The app shell, asserted here so that CampaignSignInPageTest's claim
        // that this same selector is *absent* on a central page is evidence
        // rather than a selector that never matches anything.
        ->assertPresent('[data-test="sidebar-menu-button"]')

        ->assertNoJavaScriptErrors();

    // Both controls are asked the same question -- "did anything intercept this
    // click?" -- and are required to answer it differently.
    //
    // An Inertia <Link> calls `event.preventDefault()` inside its own click
    // handler before starting a visit; a plain <a> leaves the event alone and
    // lets the browser navigate. So `defaultPrevented`, read after the anchor's
    // own handlers have run, says exactly which kind of link this is.
    //
    // The listener sits on `document` in the bubble phase, which is what puts it
    // *after* the handlers Vue attached to the anchor. It also cancels the
    // export click itself, so that asking the question does not start a real
    // file download in the middle of the test.
    //
    // **The rejected version is worth keeping, because it could not fail.** This
    // first asserted that Inertia's error dialog (`#inertia-error-dialog`, which
    // it mounts on a response that is not a page object) was absent after the
    // click. That assertion passed with the defect deliberately in place: the
    // dialog is raised when the XHR *returns*, and the assertion ran while the
    // request was still in flight, so it was satisfied before its own cause had
    // had time to happen. An absence assertion racing an asynchronous cause is
    // green whatever the truth. Reading `defaultPrevented` is synchronous.
    $page->script(<<<'JAVASCRIPT'
        window.__exportClickIntercepted = null;
        window.__nextClickIntercepted = null;

        document.addEventListener('click', (event) => {
            const anchor = event.target.closest('a');

            if (! anchor) {
                return;
            }

            const label = anchor.textContent.trim();

            if (label === 'Export the list') {
                window.__exportClickIntercepted = event.defaultPrevented;
                event.preventDefault();
            }

            if (label === 'Next') {
                window.__nextClickIntercepted = event.defaultPrevented;
            }
        });
    JAVASCRIPT);

    // The export must NOT be intercepted: an Inertia visit here is an XHR
    // expecting a page object and would be handed a CSV.
    //
    // `=== false` rather than a falsy check, and that is the part doing the
    // work: the flag starts as null, so a click that never reached the export
    // anchor at all fails here instead of passing as "not intercepted".
    $page->click('Export the list')
        ->assertScript('window.__exportClickIntercepted === false');

    // And paging must be, for the opposite reason. Asserted twice over: the
    // mechanism, and then the consequence.
    //
    // The marker is the consequence half. A full document load destroys the
    // JavaScript context and takes this variable with it, so its survival is
    // what proves no real navigation happened -- which is the thing that
    // actually matters, and which `defaultPrevented` alone only implies.
    $page->script('window.__pageWasNotReloaded = true;');

    $page->click('Next')
        ->assertScript('window.__nextClickIntercepted === true')
        ->assertQueryStringHas('page', '2')
        ->assertSee($first->email)
        ->assertScript('window.__pageWasNotReloaded === true')
        ->assertNoJavaScriptErrors();
});
