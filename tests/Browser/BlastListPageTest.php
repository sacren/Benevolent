<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\BlastRecipient;
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
