<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The compose page, opened in a real browser by a signed-in Owner.
 *
 * **Justified by a defect class rather than by the page it opens.** Three
 * things on this page are invisible to every server-side assertion:
 *
 *   - **A count that renders as `0` and a count that fails to render at all
 *     are the same to `assertInertia`**, which sees the prop the server sent
 *     and never the number a person reads. That is the whole hazard of putting
 *     a number on a page: the wrong one is loud, and a missing one is silent.
 *   - **A textarea's initial content is set by an attribute, not by
 *     interpolation.** `{{ }}` inside a <textarea> is a lint error precisely
 *     because it does not do what it looks like; the page uses `:value`
 *     instead, and nothing on the server can tell whether the body an operator
 *     is about to edit actually reached the field.
 *   - **The postcode field has to read back the same line the operator typed.**
 *     The server stores a list and the page joins it, so a round trip through
 *     the form is the only thing that shows the two agree.
 *
 * Reaching this page at all is the other half of the work, and it is
 * Tests\Support\LoopbackHost's job -- see that class for why claiming the
 * address has to start by releasing whoever holds it.
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

test('an owner sees who a draft would reach, and the draft itself, in the fields they will edit', function (): void {
    // Two spellings of one postcode area, which is what the campaign's list
    // really holds -- so a count that renders is also a count that is right
    // about the folding, rather than merely present.
    Supporter::factory()->create(['postcode' => 'M15 6BH']);
    Supporter::factory()->create(['postcode' => 'm156bh']);

    // And two the aim must exclude: another area, and somebody in the right
    // area who asked not to be contacted.
    Supporter::factory()->create(['postcode' => 'EH8 9YL']);
    Supporter::factory()->create([
        'postcode' => 'M15 9AA',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    $blast = Blast::factory()->narrowedToPostcodes(['M15', 'sw1a'])->create([
        'subject' => 'Object before Friday',
        'body' => "The consultation closes at five.\n\nPlease write in.",
    ]);

    $this->actingAs(User::factory()->owner()->create());

    $page = visit('/blasts/'.$blast->getKey().'/edit');

    $page
        // Vue mounted and the server's data reached it. Everything below is
        // evidence only because of this: an empty page satisfies every "this is
        // absent" claim perfectly.
        ->assertSee('Object before Friday')

        // The count, as a number a person can read. `2` is the answer only if
        // the fold caught both spellings and the status condition excluded the
        // unsubscribed supporter in the same area -- so a rendering failure and
        // a wrong audience are both caught here, and the server could report
        // neither.
        ->assertSee('2 supporters match this blast right now')

        // And the sentence that keeps it honest. Under D-14 this number is a
        // prediction rather than a promise, and the page is where that stops
        // being a design note and becomes something an operator is told.
        ->assertSee('Worked out again when the blast is sent')
        ->assertPresent('[data-test="audience-size"]')

        // The signed-in shell, which is the correct layout for this page --
        // asserted rather than assumed, because app.ts resolves layouts by page
        // name and its default arm is this shell. The page that must *not* land
        // there is Step 5's unsubscribe page.
        ->assertPresent('[data-test="sidebar-menu-button"]')

        ->assertNoJavaScriptErrors();

    // The two fields the server cannot see into. Read from the DOM rather than
    // asserted as text: a form control's value is a property, so it appears in
    // no page text and no markup assertion.
    //
    // The body is the one that would fail silently. It is set with `:value`
    // because interpolation inside a <textarea> does not populate it, and an
    // operator handed an empty box would retype a message that was already
    // written -- or save the blank over it.
    $page->assertScript(
        'document.getElementById("body").value === '
        .json_encode("The consultation closes at five.\n\nPlease write in.")
    );

    // And the aim reads back as the one line it was typed on, rather than as
    // whatever the server stored it as.
    $page->assertScript('document.getElementById("postcode_prefixes").value === "M15, sw1a"');
});

test('a campaign with nobody to write to says so in a number rather than by rendering nothing', function (): void {
    // The case the server genuinely cannot distinguish from a broken page, and
    // the reason this file exists. An empty campaign, a real count of zero, and
    // a page that has to *say* zero.
    $blast = Blast::factory()->create(['subject' => 'Nobody yet']);

    $this->actingAs(User::factory()->owner()->create());

    visit('/blasts/'.$blast->getKey().'/edit')
        ->assertSee('0 supporters match this blast right now')
        ->assertNoJavaScriptErrors();
});

test('an owner is offered the send, told it cannot be undone, and told where replies go', function (): void {
    Supporter::factory()->count(2)->create();

    $blast = Blast::factory()->create(['subject' => 'Object before Friday']);

    $this->actingAs(User::factory()->owner()->create());

    // **The first control in this application that differs by role**, so it is
    // the first place a wrong permission string renders nothing while the
    // server answers 200 with the right component name. `can()` reads a shared
    // prop against a plain string; a typo in it is invisible to every
    // server-side assertion and costs an Owner the one control they alone have.
    visit('/blasts/'.$blast->getKey().'/edit')
        ->assertPresent('[data-test="send-blast"]')
        ->assertSee('Send to 2 supporters')
        ->assertSee('Sending cannot be undone')

        // The campaign in this suite has no contact address, so this is the
        // honest default rather than a contrived case -- and it is the state
        // worth rendering, because a campaign can be in it without noticing.
        ->assertPresent('[data-test="reply-path"]')
        ->assertSee('no reply address')

        ->assertNoJavaScriptErrors();
});

test('an operator who may not send is not offered the control', function (): void {
    Supporter::factory()->create();

    $blast = Blast::factory()->create(['subject' => 'Object before Friday']);

    // Staff hold EditBlasts and not SendBlasts. The policy refuses the request
    // whatever the browser rendered, so this is a courtesy rather than the
    // guard -- but a courtesy that fails open shows an operator a button that
    // 403s, and one that fails closed hides it from the Owner too, which is why
    // both directions are asserted rather than only this one.
    $this->actingAs(User::factory()->create());

    visit('/blasts/'.$blast->getKey().'/edit')
        ->assertSee('Object before Friday')
        ->assertMissing('[data-test="send-blast"]')
        ->assertNoJavaScriptErrors();
});
