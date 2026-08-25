<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * No campaign can be left with nobody who may govern it (§7 criterion 3).
 *
 * **Asked through the surface an operator actually uses to leave**, which is
 * `DELETE settings/profile`, because that is where the door was measured open
 * at Phase 6 Step 4: the sole Owner of a campaign that also had a Staff operator
 * left through it, and nothing refused. RemoveOperator asks CampaignGovernance
 * on every path that removes an operator, and this is the path that existed
 * first.
 *
 * **Two operators in every fixture that asserts a refusal**, per the phase's §3
 * convention: a refusal with nobody else present would be satisfied by a rule
 * refusing every Owner, so each refusal sits beside the same act succeeding
 * once a second governor exists.
 */

/**
 * Whether an invitation's link would still open.
 */
function stillLive(OperatorInvitation $invitation): bool
{
    return DB::connection('tenant')->table('operator_invitations')
        ->where('id', $invitation->getKey())->whereNotNull('token')->exists();
}

test('the last owner cannot leave while somebody else stays, and can once a second owner exists', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $staff = User::factory()->create(['email' => 'helper@example.test']);

    $refused = $this->actingAs($owner)
        ->from($this->campaignUrl('settings/profile'))
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password']);

    // The refusal is the row still being there. Asserted before the error, so
    // a break that removes the operator and still reports an error reddens
    // here rather than at the message.
    expect(User::query()->whereKey($owner->getKey())->exists())->toBeTrue()
        ->and(User::query()->whereKey($staff->getKey())->exists())->toBeTrue();

    // Still signed in and still on the page, reading why.
    $this->assertAuthenticatedAs($owner);

    $refused->assertRedirect($this->campaignUrl('settings/profile'))
        ->assertSessionHasErrors('operator');

    // **The positive half, in the same run and through the same surface**:
    // once somebody else may govern, leaving is exactly what it was before.
    User::factory()->owner()->create(['email' => 'second.governor@example.test']);

    $this->actingAs($owner)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();

    expect(User::query()->orderBy('email')->pluck('email')->all())
        ->toBe(['helper@example.test', 'second.governor@example.test']);
});

test('a staff operator may leave a campaign nobody governs, which brings it closer to one the platform can repair', function (): void {
    // **Only a governor can trip the refusal**, and this is the fixture that
    // can see it. With an Owner present the check never decides anything --
    // somebody else governs, whoever is leaving -- so a rule refusing every
    // operator who leaves while others stay passes the test below and every
    // test above; found by breaking it and watching this file stay green. A
    // campaign that fell through the door before it was closed has no
    // governor to be counted, and refusing its Staff their departure would
    // hold people inside a campaign nobody can run.
    User::factory()->create(['email' => 'left.behind@example.test']);
    $staff = User::factory()->create(['email' => 'also.left@example.test']);

    $this->actingAs($staff)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect(User::query()->pluck('email')->all())->toBe(['left.behind@example.test']);
});

test('a staff operator leaves while the only owner stays', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $staff = User::factory()->create(['email' => 'helper@example.test']);

    $this->actingAs($staff)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect(User::query()->pluck('email')->all())->toBe(['governor@example.test'])
        ->and($owner->fresh())->not->toBeNull();
});

test('the only operator may leave, and the campaign is empty again', function (): void {
    // Nobody is left to be governed, and an empty campaign is the one state the
    // platform repairs: campaign:invite-owner accepts it again. Refusing here
    // would trap a sole Owner in a campaign they want to leave, for nobody's
    // benefit.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);

    $this->actingAs($owner)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect(User::query()->exists())->toBeFalse();
});

test('a sole owner who leaves takes their unused invitations with them, so nobody arrives into a campaign with no owner', function (): void {
    // **The door's second entrance, as it was measured.** Nobody else was
    // present when the Owner left, so "does anybody stay?" answered no and the
    // departure was right to succeed -- and then the Staff invitation they had
    // sent was accepted, and the campaign had one Staff operator, no Owner,
    // and a platform command that refuses any campaign with operators.
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'late@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    $token = (string) DB::connection('tenant')->table('operator_invitations')->where('email', 'late@example.test')->value('token');

    $this->actingAs($owner)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->post($this->campaignUrl('invitation/'.$token), [
        'name' => 'Late',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ])->assertNotFound();

    expect(User::query()->exists())->toBeFalse();

    // Withdrawn rather than deleted: the row still says who was invited, by
    // whom and as what, with neither a credential nor an acceptance.
    $row = DB::connection('tenant')->table('operator_invitations')->where('email', 'late@example.test')->sole();

    expect($row->token)->toBeNull()
        ->and($row->accepted_at)->toBeNull()
        ->and($row->invited_by_label)->toBe('governor@example.test');
});

test('leaving withdraws the invitations the leaver sent, and nobody else\'s', function (): void {
    // **A wrong one of every kind in the fixture** (Blueprint v0.32): another
    // Owner's live invitation, one the platform sent, and one the leaver sent
    // that was already accepted. A withdrawal of every live invitation, or of
    // every row the leaver ever sent, is refused by one of them.
    $leaving = User::factory()->owner()->create(['email' => 'leaving@example.test']);
    $staying = User::factory()->owner()->create(['email' => 'staying@example.test']);

    $theirs = OperatorInvitation::factory()->invitedBy($leaving)->create(['email' => 'theirs@example.test']);
    $theirsUsed = OperatorInvitation::factory()->invitedBy($leaving)->accepted()->create(['email' => 'used@example.test']);
    $colleagues = OperatorInvitation::factory()->invitedBy($staying)->create(['email' => 'colleagues@example.test']);
    $platforms = OperatorInvitation::factory()->owner()->create(['email' => 'platforms@example.test']);

    $this->actingAs($leaving)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(stillLive($theirs))->toBeFalse()
        ->and(stillLive($colleagues))->toBeTrue()
        ->and(stillLive($platforms))->toBeTrue()
        ->and($theirsUsed->fresh()?->accepted_at)->not->toBeNull()
        ->and($theirs->fresh()?->accepted_at)->toBeNull();
});

test('a refused departure withdraws nothing', function (): void {
    // The withdrawal runs before the refusal inside one transaction, which is
    // what closes its race with an acceptance -- so a refusal has to undo it.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    User::factory()->create(['email' => 'helper@example.test']);

    $sent = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'pending@example.test']);

    $this->actingAs($owner)
        ->delete($this->campaignUrl('settings/profile'), ['password' => 'password'])
        ->assertSessionHasErrors('operator');

    expect(stillLive($sent))->toBeTrue()
        ->and($owner->fresh())->not->toBeNull()
        ->and($owner->fresh()?->role)->toBe(OperatorRole::Owner);
});
