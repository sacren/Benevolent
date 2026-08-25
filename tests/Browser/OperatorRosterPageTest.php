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
