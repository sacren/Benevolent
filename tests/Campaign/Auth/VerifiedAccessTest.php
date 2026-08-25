<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;

/*
 * Whether an operator must have proved their address before reaching the
 * campaign (D-60).
 *
 * **Phase 6 Step 1 found that nothing pinned either answer.** Every campaign
 * route sat behind `verified`, the middleware refused nobody because User did
 * not implement MustVerifyEmail, and 691 of 691 non-browser tests stayed green
 * whichever way the contract was set. EmailVerificationTest exercises the
 * machinery -- the signed route, the event, the flag -- and never asserted that
 * an unverified operator is refused anything. This file is that assertion,
 * in both directions, and it does not skip on a feature flag.
 *
 * Two operators in each test, one verified and one not, so a refusal cannot be
 * satisfied by a route that refuses everybody.
 */

test('the operator model carries the contract the verified middleware reads', function (): void {
    // The configuration invariant behind the behaviour below (L-14's pairing):
    // EnsureEmailIsVerified refuses only a user implementing this.
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class);
});

test('an operator who has not proved their address is refused the campaign, and one who has is let in', function (): void {
    $unproven = User::factory()->owner()->unverified()->create();
    $proven = User::factory()->owner()->create();

    $this->actingAs($unproven)->get($this->campaignUrl('supporters'))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($proven)->get($this->campaignUrl('supporters'))->assertOk();
});

test('changing one\'s address withholds the campaign until the new one is proved, and the profile says why', function (): void {
    $changing = User::factory()->create(['email' => 'before@example.test']);
    $colleague = User::factory()->create(['email' => 'colleague@example.test']);

    $this->actingAs($changing)
        ->patch(route('profile.update'), ['name' => 'Changing', 'email' => 'after@example.test'])
        ->assertSessionHasNoErrors();

    $this->actingAs($changing->refresh())->get($this->campaignUrl('dashboard'))
        ->assertRedirect(route('verification.notice'));

    // The profile page stays reachable -- it sits behind `auth` alone -- and
    // now tells them, which is the block Step 1 found could never render.
    $this->actingAs($changing)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('mustVerifyEmail', true));

    // The colleague who changed nothing is untouched.
    $this->actingAs($colleague)->get($this->campaignUrl('dashboard'))->assertOk();
});

test('accepting an invitation is proof of the address, so nobody who joins is asked twice', function (): void {
    // The invitation reached the address, so the operator it creates arrives
    // verified (D-60) and goes straight to work -- no second mail to prove
    // the fact the first one already proved.
    $invitation = OperatorInvitation::factory()->create(['email' => 'newcomer@example.test']);
    OperatorInvitation::factory()->create(['email' => 'bystander@example.test']);

    $token = (string) DB::connection('tenant')->table('operator_invitations')->where('id', $invitation->getKey())->value('token');

    $this->post($this->campaignUrl('invitation/'.$token), [
        'name' => 'Newcomer',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ])->assertRedirect(route('dashboard'));

    $this->get($this->campaignUrl('supporters'))->assertOk();
});
