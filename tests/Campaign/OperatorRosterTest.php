<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The roster (D-57): who runs a campaign, and who has been invited to.
 *
 * **Two operators in every authority test, holding the two roles**, and the
 * deny paired with the allow through the same call (L-14): a policy denies an
 * ability it has no method for exactly as it denies one it refused, so a
 * refusal alone would pass against a policy that was never wired.
 */

test('an owner is shown the roster, and staff are refused it', function (): void {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $this->actingAs($owner)->get($this->campaignUrl('operators'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('operators/Index'));

    $this->actingAs($staff)->get($this->campaignUrl('operators'))->assertForbidden();
});

test('the roster says who admitted each operator, and "not recorded" where nothing does', function (): void {
    // **§7 criterion 4, against the demo campaign's own shape**: an Owner who
    // existed before invitations were recorded has none, and the answer for
    // them is null -- never an inviter borrowed from somebody else's row.
    //
    // **A wrong candidate of every kind** (Blueprint v0.32), so that "the right
    // invitation" is not the only one a careless match could find: another
    // operator's accepted invitation, an older acceptance for the same person
    // by somebody else, one sent in another casing, one withdrawn before it
    // was used, and one the platform sent.
    $seeded = User::factory()->owner()->create(['name' => 'Avery Seeded', 'email' => 'seeded@example.test']);
    $invited = User::factory()->create(['name' => 'Blake Invited', 'email' => 'invited@example.test']);
    $returned = User::factory()->create(['name' => 'Casey Returned', 'email' => 'returned@example.test']);
    $platforms = User::factory()->owner()->create(['name' => 'Drew Platform', 'email' => 'platform@example.test']);
    $withdrawn = User::factory()->create(['name' => 'Emery Withdrawn', 'email' => 'withdrawn@example.test']);

    OperatorInvitation::factory()->invitedBy($seeded)->accepted()->create(['email' => 'Invited@Example.test']);

    // Joined once on somebody else's say, left, and came back on the seeded
    // Owner's: the second admission is the one that answers "who let them in".
    OperatorInvitation::factory()->accepted()->create([
        'email' => 'returned@example.test',
        'invited_by_label' => 'former.owner@example.test',
        'accepted_at' => now()->subYear(),
    ]);
    OperatorInvitation::factory()->invitedBy($seeded)->accepted()->create(['email' => 'returned@example.test']);

    OperatorInvitation::factory()->owner()->accepted()->create(['email' => 'platform@example.test']);

    // Withdrawn: no credential and no acceptance. It admitted nobody.
    OperatorInvitation::factory()->invitedBy($seeded)->create(['email' => 'withdrawn@example.test', 'token' => null]);

    $this->actingAs($seeded)->get($this->campaignUrl('operators'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operators/Index')
            ->has('operators', 5)
            ->where('operators.0.email', 'seeded@example.test')
            ->where('operators.0.admitted', null)
            ->where('operators.0.is_you', true)
            ->where('operators.0.role', 'owner')
            ->where('operators.1.email', 'invited@example.test')
            ->where('operators.1.admitted', ['by' => 'seeded@example.test'])
            ->where('operators.1.is_you', false)
            ->where('operators.1.role', 'staff')
            ->where('operators.2.email', 'returned@example.test')
            ->where('operators.2.admitted', ['by' => 'seeded@example.test'])
            ->where('operators.3.email', 'platform@example.test')
            ->where('operators.3.admitted', ['by' => null])
            ->where('operators.4.email', 'withdrawn@example.test')
            ->where('operators.4.admitted', null)
        );

    // The fixture's own claims, asserted so the ones the page must not have
    // chosen are known to be there: two acceptances for one address, and a
    // withdrawn row naming an operator's address.
    expect(DB::connection('tenant')->table('operator_invitations')->where('email', 'returned@example.test')->count())->toBe(2)
        ->and(DB::connection('tenant')->table('operator_invitations')->where('email', 'withdrawn@example.test')->whereNull('token')->whereNull('accepted_at')->count())->toBe(1)
        ->and($invited->exists && $returned->exists && $platforms->exists && $withdrawn->exists)->toBeTrue();
});

test('the invitations listed are the ones still waiting, whoever sent them', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);

    OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'waiting@example.test', 'created_at' => now()->subDay()]);
    OperatorInvitation::factory()->owner()->create(['email' => 'platform.waiting@example.test']);

    // The two that must not appear: one already used, one withdrawn.
    OperatorInvitation::factory()->invitedBy($owner)->accepted()->create(['email' => 'used@example.test']);
    OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'withdrawn@example.test', 'token' => null]);

    $this->actingAs($owner)->get($this->campaignUrl('operators'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invitations', 2)
            ->where('invitations.0.email', 'platform.waiting@example.test')
            ->where('invitations.0.role', 'owner')
            ->where('invitations.0.invited_by', null)
            ->where('invitations.1.email', 'waiting@example.test')
            ->where('invitations.1.role', 'staff')
            ->where('invitations.1.invited_by', 'governor@example.test')
            // The credential is a live link to this campaign's governance and
            // has no business in a page's props.
            ->missing('invitations.0.token')
            ->missing('invitations.1.token')
        );
});
