<?php

declare(strict_types=1);

use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Supporter;
use App\Models\User;
use App\Tenancy\CampaignSeat;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The supporter list, opened in a real browser by a signed-in Owner.
 *
 * This file exists for two things the server cannot see. The second, added at
 * Phase 4 Step 4, is the district column: the server sends a split ZIP code's
 * districts and claims none of them, and only the rendered page can show
 * whether it then presents one as the supporter's own, or puts "your seat"
 * beside a supporter the server said is not in it -- the second and third
 * tests.
 *
 * The first is a contrast. The page carries two controls that must be
 * *different kinds of link, for opposite reasons*:
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
 * *is* the campaign, which is Tests\Support\LoopbackHost's job.
 */

uses(RunsInCampaignContext::class);

beforeEach(function (): void {
    $this->enterCampaignContext();

    // Both arrangements the browser needs, and why they have to be shared
    // rather than repeated, are in Tests\Support\LoopbackHost. They were four
    // lines here until a second browser file existed and was refused the
    // address this one had already taken.
    LoopbackHost::claimFor($this->campaign);
});

afterEach(function (): void {
    LoopbackHost::release();

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

test('a ZIP code inside one district shows that district, and one crossing a boundary shows none as the supporter\'s', function (): void {
    // **The claim is made in the rendering, which is why this is a browser
    // test.** The server sends a split ZIP code's districts in `touching` and
    // leaves `claimed` null, and tests/Campaign/SupporterListDistrictTest.php
    // holds it to that. A page that showed `touching[0]` in the district column
    // would tell a campaign that somebody in 90210 is CA-30's constituent --
    // exit criterion 3's forbidden failure -- while every server-side assertion
    // stayed green, because the props would be exactly right.
    $placed = Supporter::factory()->create(['postcode' => '90232']);
    $split = Supporter::factory()->create(['postcode' => '90210']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/supporters')
        ->assertSee('2 people on this campaign’s list')

        // The placed row is the positive half, and it is what makes the absence
        // below evidence: it proves the selector shape matches something at all.
        ->assertSeeIn("[data-test=\"district-claimed-{$placed->getKey()}\"]", 'CA-37')

        // No district is shown as the split row's own. First, because it is the
        // assertion that names the defect: with the page broken to show
        // `touching[0]`, the "not named" text below disappears too, and while
        // that assertion came first the break was reported there and this line
        // was never reached.
        ->assertMissing("[data-test=\"district-claimed-{$split->getKey()}\"]")
        // And the row rendered, as "not named", which is what makes the absence
        // above evidence about this row rather than about a row that was never
        // drawn.
        ->assertSeeIn(
            "[data-test=\"district-not-named-{$split->getKey()}\"]",
            'Not named: this ZIP code crosses CA-30, CA-32, CA-36',
        )

        // And the map every answer on the page was read against (D-43).
        ->assertSeeIn('table > thead', '119th Congress')
        ->assertSeeIn('[data-test="district-map"]', 'Districts are those of the 119th Congress')
        ->assertSeeIn('[data-test="district-map"]', 'published October 24, 2024')
        ->assertNoJavaScriptErrors();
});

test('against the campaign\'s seat, only a ZIP code wholly inside it is shown as in it', function (): void {
    // "Your seat" beside a row is a claim that the supporter is the campaign's
    // constituent, so it is the same forbidden failure as a wrong district, asked
    // about one district. The server decides the standing; this checks the page
    // shows "your seat" only where the server said `in` -- a page that checked
    // only that there was a standing would show it beside the CA-37 row too.
    $in = Supporter::factory()->create(['postcode' => '02141']);
    $elsewhere = Supporter::factory()->create(['postcode' => '90232']);
    $maybe = Supporter::factory()->create(['postcode' => '02139']);
    $notIn = Supporter::factory()->create(['postcode' => '90210']);

    $relation = ZctaDistricts::shipped();
    $seat = Seat::parse('MA-07', $relation);
    expect($seat)->not->toBeNull();

    // The harness keeps one campaign per file and re-reads its registry row for
    // every test, so a seat left behind would reach the tests beside this one.
    CampaignSeat::store($this->campaign, $seat);

    try {
        $this->actingAs(User::factory()->owner()->create());

        visit('/supporters')
            ->assertSee('4 people on this campaign’s list')

            // In the seat, and the positive half that makes the absences below
            // evidence about the marker rather than about a selector.
            ->assertSeeIn("[data-test=\"district-claimed-{$in->getKey()}\"]", 'MA-07')
            ->assertPresent("[data-test=\"district-in-seat-{$in->getKey()}\"]")

            // Placed, in another seat: its district is named, and it is not
            // shown as the campaign's.
            ->assertMissing("[data-test=\"district-in-seat-{$elsewhere->getKey()}\"]")
            ->assertSeeIn("[data-test=\"district-claimed-{$elsewhere->getKey()}\"]", 'CA-37')

            // Crossing the seat's boundary: may be in it, never in it.
            ->assertMissing("[data-test=\"district-claimed-{$maybe->getKey()}\"]")
            ->assertSeeIn(
                "[data-test=\"district-not-named-{$maybe->getKey()}\"]",
                'May be in MA-07: this ZIP code crosses MA-05, MA-07',
            )

            // Crossing districts none of which is the seat: definitely not in it.
            ->assertSeeIn(
                "[data-test=\"district-not-named-{$notIn->getKey()}\"]",
                'Not in MA-07: this ZIP code crosses CA-30, CA-32, CA-36',
            )

            // And what "your seat" means, with the map it was read against.
            ->assertSeeIn('[data-test="district-map"]', 'This campaign is running for MA-07')
            ->assertSeeIn('[data-test="district-map"]', 'as the 119th Congress drew it')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->campaign->setAttribute(CampaignSeat::KEY, null);
        $this->campaign->save();
    }
});
