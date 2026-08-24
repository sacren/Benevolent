<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The invitation page, opened in a real browser by somebody with no account.
 *
 * **Two defect classes live on this page and the server cannot report either**
 * -- the unsubscribe page's two, arriving on a second public surface.
 *
 * **The first is L-12: the layout resolver.** `resources/js/app.ts` picks a
 * shell by page name and its default arm is AppLayout, the signed-in shell. A
 * page named anything the resolver does not know renders a campaign's sidebar
 * and an account menu to a stranger while the route answers 200 with the right
 * component name, so every assertion in tests/Campaign/InvitationAcceptanceTest.php
 * passes either way. The name is registered in the resolver's `null` arm; only
 * opening the page shows it landed there.
 *
 * **The second is the form's action, built in the browser** by Wayfinder from
 * the token prop. The campaign tests POST the URL by hand, so they would stay
 * green against a control wired to the wrong route or to no token at all.
 * Clicking it is the only thing that exercises what an invitee triggers -- and
 * landing inside the shell afterwards, signed in, is the evidence it did.
 *
 * Two invitations in the fixture, per the phase's §3 convention, so the page
 * naming the right address is a claim the second one could falsify.
 */

uses(RunsInCampaignContext::class);

beforeEach(function (): void {
    $this->enterCampaignContext();

    LoopbackHost::claimFor($this->campaign);

    // Caller-keyed and platform-wide (L-24); tests/Campaign/InvitationAcceptanceTest.php
    // spends the budget on purpose.
    app('cache')->driver()->flush();
});

afterEach(function (): void {
    LoopbackHost::release();

    $this->leaveCampaignContext();
});

test('somebody invited opens their link outside the application shell, joins, and lands inside it', function (): void {
    $theirs = OperatorInvitation::factory()->owner()->create(['email' => 'Ama.Boateng@Example.test']);
    OperatorInvitation::factory()->create(['email' => 'somebody.else@example.test']);

    $token = (string) DB::connection('tenant')->table('operator_invitations')->where('id', $theirs->getKey())->value('token');

    $page = visit('/invitation/'.$token);

    $page
        // First, that Vue mounted at all: an empty `<div id="app">` satisfies
        // every "not the shell" claim below perfectly.
        ->assertSee('You have been invited to help run this campaign')
        ->assertSee($this->campaign->name)
        ->assertSeeIn('[data-test="invitation-email"]', 'Ama.Boateng@Example.test')
        ->assertSeeIn('[data-test="invitation-role"]', 'an Owner')
        ->assertPresent('[data-test="invitation-accept"]')

        // **The L-12 claim.** The selector engine demonstrably resolves
        // `data-test` attributes on this page (asserted present just above),
        // and the same selector is asserted *present* by the shell-wearing
        // pages' browser files, so this absence cannot be a selector the plugin
        // failed to parse.
        ->assertMissing('[data-test="sidebar-menu-button"]')
        ->assertDontSee('Supporters')
        ->assertNoJavaScriptErrors();

    $page->fill('name', 'Ama Boateng')
        ->fill('password', 'a-memorable-passphrase')
        ->fill('password_confirmation', 'a-memorable-passphrase')
        ->click('[data-test="invitation-accept-button"]');

    // Signed in, and now inside the shell -- the one place the sidebar belongs,
    // which also shows the selector above could have found it.
    $page->assertPathIs('/dashboard')
        ->assertPresent('[data-test="sidebar-menu-button"]')
        ->assertNoJavaScriptErrors();

    // And the operator really exists with the invitation's authority, so the
    // page above reports a write rather than a redirect.
    expect(User::query()->sole())
        ->email->toBe('ama.boateng@example.test')
        ->role->toBe(OperatorRole::Owner);
});
