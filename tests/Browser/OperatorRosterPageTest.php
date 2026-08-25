<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\User;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The roster, opened in a real browser.
 *
 * **One defect class, and the server cannot report it: what the page says
 * where nothing records who admitted somebody (§7 criterion 4).** The server
 * sends `admitted: null` for such an operator, and
 * tests/Campaign/OperatorRosterTest.php pins that -- but the words are chosen
 * in the page. A page that rendered the null as an empty cell, as "Invited by
 * " with nothing after it, or as the platform's invitation would pass every
 * server-side assertion, and the last of those is the fabrication the
 * criterion exists to refuse. Only rendering it shows which it says.
 *
 * **And a second: the Withdraw control's action is built in the browser** by
 * Wayfinder from the invitation's id, so the campaign tests, which issue the
 * DELETE by hand, would stay green against a control wired to the wrong route
 * or the wrong row. Clicking it, and watching that row -- and only that row --
 * leave the list, is the only thing that exercises what an Owner triggers.
 *
 * **And a third: the role control**, built in the browser the same way, and
 * the last Owner's refusal when they try to step down, which the server sends
 * under a key only this page decides whether to show -- an Owner clicking
 * "Make Staff" and seeing nothing happen would be the dialog defect
 * LeavingACampaignPageTest guards, on the other surface that reaches the door.
 *
 * The fixture is the demo campaign's shape -- one Owner nobody invited --
 * beside an operator somebody did invite and one the platform did, so "not
 * recorded" appearing on the right row is a claim the other two rows could
 * falsify.
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

test('the roster says who admitted each operator, and "not recorded" where nothing does', function (): void {
    $seeded = User::factory()->owner()->create(['name' => 'Avery Seeded', 'email' => 'seeded@example.test']);
    $invited = User::factory()->create(['name' => 'Blake Invited', 'email' => 'invited@example.test']);
    $platforms = User::factory()->owner()->create(['name' => 'Drew Platform', 'email' => 'platform@example.test']);

    OperatorInvitation::factory()->invitedBy($seeded)->accepted()->create(['email' => 'invited@example.test']);
    OperatorInvitation::factory()->owner()->accepted()->create(['email' => 'platform@example.test']);
    $waiting = OperatorInvitation::factory()->invitedBy($seeded)->create(['email' => 'waiting@example.test']);

    $this->actingAs($seeded);

    visit('/operators')
        // First, that Vue mounted at all.
        ->assertSee('people run this campaign')
        ->assertSeeIn('[data-test="operator-admitted-'.$seeded->getKey().'"]', 'Not recorded')
        ->assertSeeIn('[data-test="operator-admitted-'.$invited->getKey().'"]', 'Invited by seeded@example.test')
        ->assertSeeIn('[data-test="operator-admitted-'.$platforms->getKey().'"]', 'Invited by the platform')
        ->assertSeeIn('[data-test="operator-role-'.$invited->getKey().'"]', 'Staff')
        ->assertSeeIn('[data-test="invitation-'.$waiting->getKey().'"]', 'waiting@example.test')
        ->assertNoJavaScriptErrors();
});

test('an owner withdraws one invitation from the roster, and the other stays listed', function (): void {
    $owner = User::factory()->owner()->create(['name' => 'Avery Governor', 'email' => 'governor@example.test']);

    $staying = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'staying@example.test']);
    $withdrawn = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'withdrawn@example.test']);

    $this->actingAs($owner);

    $page = visit('/operators');

    $page->assertSeeIn('[data-test="invitation-'.$withdrawn->getKey().'"]', 'withdrawn@example.test')
        ->click('[data-test="withdraw-invitation-'.$withdrawn->getKey().'"]');

    $page->assertSee('The invitation to withdrawn@example.test is withdrawn.')
        ->assertMissing('[data-test="invitation-'.$withdrawn->getKey().'"]')
        ->assertSeeIn('[data-test="invitation-'.$staying->getKey().'"]', 'staying@example.test')
        ->assertNoJavaScriptErrors();

    // The click reached the writer, and the right row.
    expect($withdrawn->fresh()?->token)->toBeNull()
        ->and($staying->fresh()?->token)->not->toBeNull();
});

test('an owner makes somebody an owner from the roster, and the last owner is told why they cannot step down', function (): void {
    $owner = User::factory()->owner()->create(['name' => 'Avery Governor', 'email' => 'governor@example.test']);
    $staff = User::factory()->create(['name' => 'Blake Helper', 'email' => 'helper@example.test']);
    $other = User::factory()->create(['name' => 'Casey Other', 'email' => 'other@example.test']);

    $this->actingAs($owner);

    $page = visit('/operators');

    // **The refusal first**, while the Owner is the only one: their own row
    // offers "Make Staff", and the page must say why nothing changed.
    $page->click('[data-test="change-role-'.$owner->getKey().'"]');

    $page->assertSeeIn('[data-test="operator-refusal"]', 'You are the last operator who can govern this campaign')
        ->assertSeeIn('[data-test="operator-role-'.$owner->getKey().'"]', 'Owner');

    // **Then the promotion**, on one Staff row of two.
    $page->click('[data-test="change-role-'.$staff->getKey().'"]');

    $page->assertSee('helper@example.test is now an Owner.')
        ->assertSeeIn('[data-test="operator-role-'.$staff->getKey().'"]', 'Owner')
        ->assertSeeIn('[data-test="operator-role-'.$other->getKey().'"]', 'Staff')
        ->assertNoJavaScriptErrors();

    expect($staff->fresh()?->role->value)->toBe('owner')
        ->and($other->fresh()?->role->value)->toBe('staff');
});
