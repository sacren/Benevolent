<?php

declare(strict_types=1);

use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Segment;
use App\Models\Supporter;
use App\Models\User;
use App\Tenancy\CampaignSeat;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The segment pages, and the supporter list being narrowed by one, opened in a
 * real browser by a signed-in Owner.
 *
 * **Justified by the defect classes it alone can catch, not by the pages it
 * opens.** There are three here, and four more for district segments further
 * down, and every one of them is invisible to a server-side assertion:
 *
 *   1. **A page whose layout depends on its own props may never mount.**
 *      defineOptions() is hoisted out of <script setup>, so a breadcrumb built
 *      from `segment.id` throws ReferenceError, Vue renders nothing, and the
 *      route still answers 200 with the right component name. That defect
 *      shipped once in this repository. segments/Edit.vue is written the only
 *      way that works -- Inertia's layout *callback*, which is handed the props
 *      -- and this is the only thing that would notice a regression to the
 *      static form.
 *
 *   2. **A narrowing control and an export control render as the same thing and
 *      must behave as opposite kinds of link.** Narrowing must be a client-side
 *      visit; the export must be a plain navigation, because that route answers
 *      with a file and an Inertia visit would be handed a CSV where it expected
 *      a page object. Both are `<a href>` or a form in the DOM.
 *
 *   3. **The export's href is computed in the browser from the narrowing.** The
 *      server sends `narrowedTo`; the page turns it into `?segment=` on an
 *      anchor. A server assertion can see the prop and cannot see whether the
 *      anchor got it -- and if it did not, an Owner who narrowed to a handful of
 *      people downloads the whole list, which is precisely the surprise the
 *      decision to make the export follow the narrowing exists to prevent.
 *
 * Kept minimal otherwise. What a segment stores, who may touch one and which
 * rows a narrowing returns are covered by the campaign suite, and repeating
 * them here would pay a browser's cost for assertions the server already makes.
 */

uses(RunsInCampaignContext::class);

beforeEach(function (): void {
    $this->enterCampaignContext();

    LoopbackHost::claimFor($this->campaign);
});

afterEach(function (): void {
    LoopbackHost::release();

    $this->leaveCampaignContext();
});

test('the segment pages mount, including the one whose layout reads its own props', function (): void {
    $segment = Segment::factory()
        ->narrowedToPostcodes(['902', '911'])
        ->create(['name' => 'Cambridge and Somerville']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/segments')
        // Vue mounted and the server's data reached the template. Everything
        // else is evidence only because of this: an empty page would satisfy
        // every "this is absent" claim perfectly.
        ->assertSee('Cambridge and Somerville')
        ->assertSee('902, 911')
        ->assertPresent('table > tbody')
        // The app shell, which is the default arm of app.ts's layout resolver.
        // A campaign page *wants* AppLayout, so no resolver arm was added for
        // segments -- and this is what says the default actually reached it,
        // since a page in the wrong shell still answers 200 with the right
        // component name (L-12).
        ->assertPresent('[data-test="sidebar-menu-button"]')
        ->assertNoJavaScriptErrors();

    // Defect class 1. The heading is the segment's own name, so seeing it proves
    // both that Vue mounted and that the prop reached the template -- and a
    // static defineOptions() here would throw before either happened, leaving a
    // blank page behind a 200.
    visit('/segments/'.$segment->getKey().'/edit')
        ->assertSee('Cambridge and Somerville')
        // Read back into the field as one line, which is where the stored list
        // becomes the text an operator typed again.
        ->assertValue('#postcode_prefixes', '902, 911')
        ->assertNoJavaScriptErrors();
});

test('narrowing the list is a client-side visit, and it reaches the export control', function (): void {
    Supporter::factory()->create(['email' => 'inside@example.test', 'postcode' => '90210']);
    Supporter::factory()->create(['email' => 'outside@example.test', 'postcode' => '02139']);

    $segment = Segment::factory()
        ->narrowedToPostcodes(['902'])
        ->create(['name' => 'Cambridge']);

    $this->actingAs(User::factory()->owner()->create());

    $page = visit('/supporters');

    $page
        ->assertSee('2 people on this campaign’s list')
        ->assertPresent('[data-test="narrow-to-segment"]')
        ->assertNoJavaScriptErrors();

    // Before narrowing, the export control asks for the whole list. Asserted so
    // that the assertion after narrowing is a *change* rather than a value that
    // might always have been there.
    $page->assertDontSee('Export Cambridge');

    // Defect class 2, the consequence half. A full document load destroys the
    // JavaScript context and takes this variable with it, so its survival after
    // the narrowing is what proves the control did a client-side visit rather
    // than an ordinary form submission -- which would work, visibly, and would
    // throw away the SPA visit.
    $page->script('window.__pageWasNotReloaded = true;');

    $page->select('[data-test="narrow-to-segment"]', (string) $segment->getKey())
        ->click('Apply');

    $page
        ->assertScript('window.__pageWasNotReloaded === true')
        ->assertQueryStringHas('segment', (string) $segment->getKey())
        // The rows actually narrowed, in the browser, which is the whole point
        // of the control existing.
        ->assertSee('1 person in Cambridge')
        ->assertSee('inside@example.test')
        ->assertDontSee('outside@example.test')
        ->assertNoJavaScriptErrors();

    // Defect class 3. The href is built in the page from `narrowedTo`, so this
    // is the only place the two halves of "the file follows the screen" are
    // ever seen to agree. Read off the anchor rather than inferred from its
    // label, because a label saying "Export Cambridge" over an href asking for
    // everybody is exactly the failure worth catching.
    $page->assertScript(
        'document.querySelector(\'[data-test="export-supporters"]\').getAttribute("href").includes("segment='.$segment->getKey().'")'
    );

    // And defect class 2's other half, in the direction that is actually
    // broken rather than merely wasteful: the export must NOT be intercepted,
    // because an Inertia visit there is an XHR expecting a page object and
    // would be handed a CSV. The listener sits on `document` in the bubble
    // phase, after any handler Vue attached to the anchor, and cancels the
    // click itself so that asking the question does not start a real download.
    //
    // `=== false` rather than a falsy check: the flag starts as null, so a
    // click that never reached the anchor fails here instead of passing as
    // "not intercepted".
    $page->script(<<<'JAVASCRIPT'
        window.__exportClickIntercepted = null;

        document.addEventListener('click', (event) => {
            const anchor = event.target.closest('a');

            if (anchor && anchor.dataset.test === 'export-supporters') {
                window.__exportClickIntercepted = event.defaultPrevented;
                event.preventDefault();
            }
        });
    JAVASCRIPT);

    $page->click('Export Cambridge')
        ->assertScript('window.__exportClickIntercepted === false');
});

/*
 * District segments (D-37). Four more defect classes a server assertion cannot
 * see, one per test so that a break in one is reported by its own test:
 *
 *   4. **A page that reads a segment's ZIP codes as a list crashes on a
 *      district segment, which has none.** `postcode_prefixes` is null there,
 *      and `.length` on null throws inside the render, so Vue shows nothing and
 *      the route still answers 200 with every prop correct.
 *   5. **The edit form must offer the field the segment's kind has.** Offered
 *      the ZIP code field instead, an operator re-aiming a district segment is
 *      shown an empty box and no district at all.
 *   6. **The choice on the create form is decided in the browser.** Which
 *      field is shown, and so which is submitted, follows a radio button no
 *      request carries until it is sent.
 *   7. **The sentence saying what a district narrowing leaves out is composed
 *      in the page** from counts the server sends, and a page that dropped or
 *      swapped them would tell an operator the district is whole.
 */

test('a district segment is listed with the map it is read against, and the page mounts', function (): void {
    $district = Segment::factory()->inDistrict('MA-07')->create(['name' => 'MA-07 supporters']);
    Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Westwood']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/segments')
        // Defect class 4. The prefix segment's row is the proof Vue mounted at
        // all; a render that threw on the district row would show neither.
        ->assertSee('Westwood')
        ->assertSeeIn(
            "[data-test=\"segment-rule-{$district->getKey()}\"]",
            'MA-07 as the 119th Congress drew it — only ZIP codes wholly inside it',
        )
        ->assertNoJavaScriptErrors();
});

test('a district segment\'s edit form offers its district and not a ZIP code field', function (): void {
    $district = Segment::factory()->inDistrict('MA-07')->create(['name' => 'MA-07 supporters']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/segments/'.$district->getKey().'/edit')
        // Defect class 5. The absence first, so a break that shows the wrong
        // field is reported here rather than on the presence below it.
        ->assertMissing('#postcode_prefixes')
        ->assertValue('#district', 'MA-07')
        ->assertSee('as the 119th Congress drew it')
        ->assertNoJavaScriptErrors();
});

test('naming a segment by district shows the district field, offers the campaign\'s seat, and saves it', function (): void {
    // The browser half of exit criterion 1 for this step: a campaign names a
    // district segment through the page it will actually use. The seat is
    // written to the registry row and undone in `finally`, because the harness
    // re-reads that row for every test in this file.
    CampaignSeat::store($this->campaign, Seat::parse('MA-07', ZctaDistricts::shipped()));

    try {
        $this->actingAs(User::factory()->owner()->create());

        $page = visit('/segments/create');

        // Before the choice: the ZIP code field, and no district field.
        $page->assertPresent('#postcode_prefixes')
            ->assertMissing('#district');

        // What a blast does with a district segment, read as the paragraph's
        // whole text rather than with assertSee: until Step 7 this page told a
        // campaign a blast could not be aimed by district at all, and a
        // substring match would pass with that sentence still beside the new
        // one.
        $page->assertScript(
            "document.querySelector('[data-test=\"segment-consumers\"]').textContent.replace(/\\s+/g, ' ').trim()"
            ." === 'A segment says where, not who may be written to. The supporter list shows everyone it names,"
            .' including anyone who has unsubscribed; a blast never writes to them. A blast aimed at a segment'
            ." that narrows by district records the ZIP codes that district claims at the moment it is sent.'"
        );

        // Defect class 6. After it: the field swapped, the ZIP code field gone
        // -- asserted first, for the reason the edit test gives -- and the
        // district field filled in with the campaign's seat.
        $page->click('[data-test="narrow-by-district"]')
            ->assertMissing('#postcode_prefixes')
            ->assertValue('#district', 'MA-07')
            ->fill('name', 'Our district')
            ->click('Save segment')
            ->assertSee('Our district')
            ->assertSee('MA-07 as the 119th Congress drew it')
            ->assertNoJavaScriptErrors();

        $saved = Segment::query()->where('name', 'Our district')->sole();

        expect($saved->district)->toBe('MA-07')
            ->and($saved->postcode_prefixes)->toBeNull();
    } finally {
        $this->campaign->setAttribute(CampaignSeat::KEY, null);
        $this->campaign->save();
    }
});

test('a list narrowed to a district segment says what it leaves out, and names the district on each row it shows', function (): void {
    $inside = Supporter::factory()->create(['email' => 'inside@example.test', 'postcode' => '02141']);
    $alsoInside = Supporter::factory()->create(['email' => 'also-inside@example.test', 'postcode' => '02115']);
    Supporter::factory()->create(['email' => 'crossing@example.test', 'postcode' => '02139']);

    $district = Segment::factory()->inDistrict('MA-07')->create(['name' => 'MA-07 supporters']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/supporters?segment='.$district->getKey())
        // Defect class 7, each count read in its own sentence so that a page
        // swapping them is caught by the one it put in the wrong place.
        ->assertSeeIn('[data-test="district-narrowing"]', 'Supporters in the 26 ZIP codes crossing its boundary are not shown')
        ->assertSeeIn('[data-test="district-narrowing"]', 'which 17 ZIP codes do')
        ->assertSeeIn('[data-test="district-narrowing"]', 'MA-07 supporters narrows to MA-07 as the 119th Congress drew it')
        // The rows the narrowing reached are the ones the district column
        // places in MA-07, and the supporter whose ZIP code crosses its
        // boundary is not among them.
        ->assertDontSee('crossing@example.test')
        ->assertSeeIn("[data-test=\"district-claimed-{$inside->getKey()}\"]", 'MA-07')
        ->assertSeeIn("[data-test=\"district-claimed-{$alsoInside->getKey()}\"]", 'MA-07')
        ->assertNoJavaScriptErrors();
});
