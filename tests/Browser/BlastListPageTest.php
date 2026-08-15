<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Segment;
use App\Models\User;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The blast list, opened in a real browser by a signed-in Owner.
 *
 * **Closes a residual Step 3 recorded rather than fixed:** this page's status
 * badges and audience summary shipped covered server-side only, and both are
 * rendered by a lookup keyed on the status union, which the server never sees.
 *
 * **The defect class this alone can catch**, in the terms Blueprint §5 asks for:
 *
 *   - **A record lookup keyed by a union renders nothing for a key it has no
 *     entry for**, and renders nothing *silently*. `assertInertia` sees a blast
 *     with `status: "queued"` and is satisfied; the person reading the page sees
 *     a blank cell where the one word telling them the message has not gone out
 *     should be. That risk is now doubled, because a second such lookup renders
 *     the progress summary beside the badge.
 *   - **"Queued" versus "sent" is the module's honesty obligation** (Finding A),
 *     and it is discharged on this page in words. No worker runs anywhere, so a
 *     campaign that cannot read the difference believes it has contacted its
 *     supporters when it has not -- and a summary that failed to render at all
 *     would read exactly like a blast with nothing to report.
 *   - **The audience summary asks about the segment before the column, and
 *     getting that order wrong says the opposite of the truth.** A blast aimed
 *     at a segment carries no `postcode_prefixes` of its own, so a summary
 *     testing the column first calls it "Everyone subscribed" -- a blast
 *     narrowed to one precinct described as going to the whole list. The server
 *     sends both fields and is satisfied either way; only the rendered
 *     sentence differs, and this is the only guard that reads it.
 *   - **A committed blast described from its segment reports a narrowing it
 *     never used (D-27).** A segment stays editable after a blast has gone out,
 *     so the row carries today's name and today's rule beside the rule the
 *     blast froze. The server sends all three and cannot tell which the page
 *     chose; the difference is one sentence, and a campaign reading it has no
 *     other way to learn that its narrowing has moved since the message went.
 *
 * Reaching the page is Tests\Support\LoopbackHost's job -- see that class for
 * why claiming the address begins by releasing whoever holds it, and note that
 * this file is the third to claim it.
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

test('the list tells a campaign what each blast has done, in words rather than in a blank cell', function (): void {
    $sent = Blast::factory()->sent()->create(['subject' => 'Went out on Thursday']);
    BlastRecipient::factory()->count(2)->ofBlast($sent)->sent()->create();
    BlastRecipient::factory()->ofBlast($sent)->failed()->create();

    $queued = Blast::factory()->queued()->create(['subject' => 'Still waiting']);

    $this->actingAs(User::factory()->owner()->create());

    $page = visit('/blasts');

    $page
        // Vue mounted and the server's rows reached it. Everything below is
        // evidence only because of this: an empty page satisfies every "this is
        // absent" claim perfectly.
        ->assertSee('Went out on Thursday')
        ->assertSee('Still waiting')

        // The badge, from a lookup keyed on the status union. A missing entry
        // renders an empty badge and no server assertion can tell.
        ->assertSee('Sent')
        ->assertSee('Queued')

        // **The obligation Finding A puts on this module.** A blast nothing has
        // picked up must be distinguishable from one that has gone, and the
        // page says which in a sentence rather than leaving it to a badge whose
        // two words look equally final to somebody who has not read the enum.
        ->assertSee('Waiting — no worker has picked this up yet')

        // What the send actually did, counted from the record. "2 reached, 1
        // refused" is the answer only if both aggregates ran and both rendered.
        ->assertSee('2 reached, 1 refused')

        ->assertPresent('[data-test="blast-progress-'.$sent->getKey().'"]')
        ->assertPresent('[data-test="blast-progress-'.$queued->getKey().'"]')

        // The signed-in shell, which is the correct layout for this page --
        // asserted rather than assumed, because app.ts resolves layouts by page
        // name and its default arm is this shell.
        ->assertPresent('[data-test="sidebar-menu-button"]')

        ->assertNoJavaScriptErrors();
});

test('a send that stopped says why, where the campaign can read it', function (): void {
    $failed = Blast::factory()->failed()->create([
        'subject' => 'Stopped early',
        'failure_reason' => 'The list could not be read: the database refused the write.',
    ]);

    BlastRecipient::factory()->ofBlast($failed)->sent()->create();

    $this->actingAs(User::factory()->owner()->create());

    // Central `failed_jobs` has no campaign column and no campaign surface
    // reads it, so a reason written only there is one nobody in the campaign
    // can see. This is the page where it stops being invisible.
    visit('/blasts')
        ->assertSee('1 reached before it stopped')
        ->assertSee('The list could not be read')
        ->assertPresent('[data-test="blast-failure-'.$failed->getKey().'"]')
        ->assertNoJavaScriptErrors();
});

test('a blast aimed at a segment says which one, and never that it goes to everybody', function (): void {
    // **The guard on the summary's first branch**, and it cannot be written
    // anywhere else: the ordering lives in a client-side function and the
    // server sends the same fields whichever way it is written. The committed
    // branch below it is guarded by the two tests at the foot of this file --
    // this one was the only such guard until D-27 gave the summary a second
    // question to get right.
    //
    // Confirmed by mutation rather than by pairing: inverting the draft test
    // reddens this assertion, because the draft below stops following its
    // pointer and is named by an id instead.
    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Beverly Hills']);

    Blast::factory()->aimedAtSegment($segment)->create(['subject' => 'Dockside works begin']);

    // The two neighbours it must not be confused with, on the same page in the
    // same run: one aimed by its own rule, one aimed at nobody in particular.
    Blast::factory()->narrowedToPostcodes(['021'])->create(['subject' => 'Ridge path closure']);
    Blast::factory()->create(['subject' => 'Everyone, then']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/blasts')
        ->assertSee('Dockside works begin')
        ->assertSee('Ridge path closure')
        ->assertSee('Everyone, then')

        // The segment-aimed blast is named by its narrowing rather than by an
        // id, which is why the page is handed the whole segment and not just
        // the pointer.
        ->assertSee('Subscribed in Beverly Hills')

        // The blast carrying its own rule is unchanged, so this is not passing
        // against a summary that renders one thing for everything...
        ->assertSee('Subscribed in 021')

        // ...and a blast that really does go to the whole list still says so,
        // which is what makes the assertion below a statement about the
        // *ordering* rather than about the words being absent.
        ->assertSee('Everyone subscribed')

        // The failure this exists to catch would render "Everyone subscribed"
        // three times, and the two assertions above would both still pass.
        ->assertDontSee('Subscribed in segment')

        ->assertNoJavaScriptErrors();
});

test('a sent blast says what it went out against, not what its narrowing says now', function (): void {
    // **The reporting half of D-27, and the only guard that reads the rendered
    // sentence.** The server sends the frozen rule, the segment and the status
    // whichever way the page is written; what differs is the words a campaign
    // reads. Before this, a blast sent against 911 under the name "Beverly
    // Range" rendered as "Subscribed in Cambridge" once the segment was renamed and
    // re-aimed -- today's narrowing presented as the one that went out, with
    // nothing on the page suggesting anything had moved.
    $segment = Segment::factory()->narrowedToPostcodes(['911'])->create(['name' => 'Beverly Hills']);

    Blast::factory()->aimedAtSegment($segment)->sent()->create(['subject' => 'Dockside works begin']);

    // **The control, on the same page in the same run.** A draft aimed at the
    // very same segment must still follow it, because that is what pointing is
    // for -- so this page shows one segment described two different ways, which
    // is the whole shape of D-27(a) and cannot be asserted from one row.
    Blast::factory()->aimedAtSegment($segment)->create(['subject' => 'Still being written']);

    // The narrowing moves after the send, in both ways it can: renamed, and
    // re-aimed somewhere disjoint.
    $segment->update(['name' => 'Cambridge', 'postcode_prefixes' => ['902']]);

    $this->actingAs(User::factory()->owner()->create());

    visit('/blasts')
        ->assertSee('Dockside works begin')
        ->assertSee('Still being written')

        // The sent blast reports what it froze, and says the narrowing has
        // moved rather than quietly showing today's.
        ->assertSee('Subscribed in 911 — Cambridge has changed since')

        // The draft follows the pointer to today's rule and today's name.
        ->assertSee('Subscribed in Cambridge')

        // The sent blast is never described by the rule it did not use. This
        // can fail and the obvious neighbour cannot: asserting the *old* name
        // is absent would be unbreakable, because the name a segment had when
        // a blast went out is stored nowhere and no rendering choice could put
        // it on the page. Only the rule was frozen.
        ->assertDontSee('Subscribed in 902')

        ->assertNoJavaScriptErrors();
});

test('a sent blast whose narrowing has not moved is still named by that narrowing', function (): void {
    // **Without this the drift wording could be shown unconditionally** and the
    // test above would not notice -- which would make every sent blast in the
    // product look as though its narrowing had changed, and would throw away
    // the naming Step 4 built. Where the segment still says exactly what the
    // blast froze, nothing has been lost and the name is the useful thing.
    $segment = Segment::factory()->narrowedToPostcodes(['911'])->create(['name' => 'Beverly Hills']);

    Blast::factory()->aimedAtSegment($segment)->sent()->create(['subject' => 'Dockside works begin']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/blasts')
        ->assertSee('Subscribed in Beverly Hills')
        ->assertDontSee('has changed since')
        ->assertDontSee('Subscribed in 911')
        ->assertNoJavaScriptErrors();
});

test('a sent blast aimed at a district counts the ZIP codes it froze, and names no map', function (): void {
    // **The district half of the reporting question (D-38).** A blast aimed at a
    // seat froze the ZIP codes that seat claimed, so the page counts them and
    // names the segment they came from. It deliberately names neither the seat
    // nor the Congress: `committed_zip_codes` records neither, and reading the
    // seat off the segment would describe an act committed under one map with a
    // value free to have moved since -- which is what the freeze exists to stop.
    //
    // **The segment is named without its seat in the name on purpose**, so that
    // the two absences below are assertions rather than decoration: a segment
    // called "MA-07 supporters" would put the seat on the page through its own
    // name and neither could fail.
    $segment = Segment::factory()->inDistrict('MA-07')->create(['name' => 'Home district list']);

    Blast::factory()
        ->aimedAtSegment($segment)
        ->sent()
        ->frozenToZipCodes(['02141', '02115'])
        ->create(['subject' => 'Committed under one map']);

    // The control on the same page in the same run: a draft aimed at the same
    // segment still follows it, and is named by the segment rather than counted.
    Blast::factory()->aimedAtSegment($segment)->create(['subject' => 'Still being written']);

    // The segment is re-aimed at a seat on the other coast after the send.
    $segment->update(['district' => 'CA-37']);

    $this->actingAs(User::factory()->owner()->create());

    // The aim cell of the row whose subject this is, as one expression, so the
    // two assertions below differ only in the row they read.
    $aimCellOf = static fn (string $subject): string => "Array.from(document.querySelectorAll('tbody tr'))"
        .".find(r => r.children[0].textContent.trim() === '{$subject}')"
        .".children[1].textContent.replace(/\s+/g, ' ').trim()";

    visit('/blasts')
        ->assertSee('Committed under one map')
        ->assertSee('Still being written')

        // **Read as the whole of that row's aim cell rather than with
        // assertSee, and the difference is measurable rather than stylistic.**
        // assertSee matches a substring anywhere on the page, so a summary that
        // appended the segment's current seat -- `… from Home district list —
        // CA-37`, which is exactly the defect this test exists to catch --
        // satisfied the substring and was caught only by the absence below.
        // Comparing the cell's whole text catches it on the sentence itself.
        //
        // Counted rather than listed: a seat holds up to 494 ZIP codes, and a
        // list of them is not a sentence anybody reads.
        ->assertScript($aimCellOf('Committed under one map')
            ." === 'Subscribed in the 2 ZIP codes frozen from Home district list'")

        // The draft follows the pointer, so it is named by the segment -- and
        // is read the same exact way, which is also what stops the two rows'
        // sentences being confused for one another by a substring match.
        ->assertScript($aimCellOf('Still being written')
            ." === 'Subscribed in Home district list'")

        // Neither seat reaches this page: not the one the segment names today,
        // and not the one it named when the blast went out. The second is the
        // one that can only pass by the page refusing to reconstruct it, since
        // the row still points at the segment.
        ->assertDontSee('CA-37')
        ->assertDontSee('MA-07')

        ->assertNoJavaScriptErrors();
});
