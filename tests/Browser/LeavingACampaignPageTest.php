<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * Leaving a campaign from the profile page, opened in a real browser.
 *
 * **One defect class, and the server cannot report it.** The last operator who
 * can govern a campaign is refused their departure while anybody else stays
 * (RemoveOperator), and the refusal comes back as a validation error keyed
 * `operator` -- a key the dialog never displayed before Phase 6 Step 4, since
 * the only thing that could go wrong there was the password.
 * tests/Campaign/OperatorGovernanceTest.php asserts the error is in the session
 * and would stay green against a dialog that drops it on the floor, in which
 * case the Owner clicks "Delete account", nothing appears to happen, and
 * nothing says why. Only rendering the dialog shows the reason reached them.
 *
 * Two operators, per the phase's §3 convention: the refusal needs somebody
 * who stays, and the Staff operator leaving freely in the same file shows the
 * dialog's submit path works at all.
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

test('the last owner is told in the dialog why they cannot leave, and staff can', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $staff = User::factory()->create(['email' => 'helper@example.test']);

    $this->actingAs($owner);

    $page = visit('/settings/profile');

    $page->click('[data-test="delete-user-button"]')
        ->fill('password', 'password')
        ->click('[data-test="confirm-delete-user-button"]');

    $page->assertSeeIn('[data-test="delete-user-refusal"]', 'You are the last operator who can govern this campaign')
        ->assertPathIs('/settings/profile')
        ->assertNoJavaScriptErrors();

    expect($owner->fresh())->not->toBeNull();

    // **The positive half through the same dialog**: nothing about it refuses
    // an operator who does not govern, and the click really does remove them.
    $this->actingAs($staff);

    $page = visit('/settings/profile');

    $page->click('[data-test="delete-user-button"]')
        ->fill('password', 'password')
        ->click('[data-test="confirm-delete-user-button"]');

    // Waited for rather than assumed: the removal is the request the click
    // started, and this is what shows it has answered.
    $page->assertPathIsNot('/settings/profile');

    expect($staff->fresh())->toBeNull();
});
