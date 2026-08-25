<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\OperatorRole;
use App\Models\AuditEntry;
use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Changing what somebody on the roster may do (D-57, D-58).
 *
 * **Two of everything the change discriminates between**, per the phase's §3
 * convention: a second Staff operator beside the one promoted, a second Owner
 * beside the one who steps down, and invitations sent by somebody other than
 * the operator whose authority moves.
 */

/**
 * Whether an invitation's link would still open.
 */
function linkStillOpens(OperatorInvitation $invitation): bool
{
    return DB::connection('tenant')->table('operator_invitations')
        ->where('id', $invitation->getKey())->whereNotNull('token')->exists();
}

test('an owner makes somebody an owner, and the trail records it through the page with the owner as the actor', function (): void {
    // **The first producer of `operator-role-changed`** (the kickoff's finding
    // 1). OperatorAuthorityAuditTest drives the observer by assigning a role
    // directly; this asks whether the entry the trail already knows how to
    // write is written *through the surface*, naming the Owner who did it.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $promoted = User::factory()->create(['email' => 'promoted@example.test']);
    $bystander = User::factory()->create(['email' => 'bystander@example.test']);

    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$promoted->getKey()), ['role' => 'owner'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    expect($promoted->fresh()?->role)->toBe(OperatorRole::Owner)
        ->and($bystander->fresh()?->role)->toBe(OperatorRole::Staff);

    $entry = AuditEntry::query()->where('event', AuditEvent::OperatorRoleChanged->value)->sole();

    expect($entry->subject_id)->toBe($promoted->getKey())
        ->and($entry->subject_label)->toBe('promoted@example.test')
        ->and($entry->changes)->toBe(['role' => ['from' => 'staff', 'to' => 'owner']])
        ->and($entry->actor_id)->toBe($owner->getKey())
        ->and($entry->actor_label)->toBe('governor@example.test');
});

test('staff cannot change anybody\'s role, their own included', function (): void {
    // Paired with the test above through the same route (L-14).
    User::factory()->owner()->create();
    $staff = User::factory()->create();
    $colleague = User::factory()->create();

    $this->actingAs($staff)
        ->patch($this->campaignUrl('operators/'.$colleague->getKey()), ['role' => 'owner'])
        ->assertForbidden();

    $this->actingAs($staff)
        ->patch($this->campaignUrl('operators/'.$staff->getKey()), ['role' => 'owner'])
        ->assertForbidden();

    expect($colleague->fresh()?->role)->toBe(OperatorRole::Staff)
        ->and($staff->fresh()?->role)->toBe(OperatorRole::Staff);
});

test('the last owner cannot step down while anybody stays, and can once a second owner exists', function (): void {
    // **§7 criterion 3's other surface.** The roster offers an Owner "Make
    // Staff" on their own row, and for the last one that is the door as surely
    // as leaving is -- the campaign would be run by people none of whom may
    // govern it, and whom the platform will not replace.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    User::factory()->create(['email' => 'helper@example.test']);

    $refused = $this->actingAs($owner)
        ->from($this->campaignUrl('operators'))
        ->patch($this->campaignUrl('operators/'.$owner->getKey()), ['role' => 'staff']);

    // The refusal is the role staying where it was, asserted before the error.
    expect($owner->fresh()?->role)->toBe(OperatorRole::Owner);

    $refused->assertRedirect($this->campaignUrl('operators'))
        ->assertSessionHasErrors('operator');

    expect(AuditEntry::query()->where('event', AuditEvent::OperatorRoleChanged->value)->exists())->toBeFalse();

    // **The positive half, in the same run and through the same surface.**
    User::factory()->owner()->create(['email' => 'second.governor@example.test']);

    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$owner->getKey()), ['role' => 'staff'])
        ->assertSessionHasNoErrors();

    expect($owner->fresh()?->role)->toBe(OperatorRole::Staff);
});

test('an owner who stops governing takes their unused invitations with them, and nobody else\'s', function (): void {
    // An invitation stands on the authority of whoever sent it, and that
    // authority is what stepping down gives up -- the same rule leaving
    // follows. A wrong one of each kind sits beside it: the demoting Owner's
    // own invitation, one the platform sent, and one the demoted Owner sent
    // that was already accepted.
    $demoting = User::factory()->owner()->create(['email' => 'demoting@example.test']);
    $demoted = User::factory()->owner()->create(['email' => 'demoted@example.test']);

    $theirs = OperatorInvitation::factory()->invitedBy($demoted)->create(['email' => 'theirs@example.test']);
    $theirsUsed = OperatorInvitation::factory()->invitedBy($demoted)->accepted()->create(['email' => 'used@example.test']);
    $demotersOwn = OperatorInvitation::factory()->invitedBy($demoting)->create(['email' => 'mine@example.test']);
    $platforms = OperatorInvitation::factory()->owner()->create(['email' => 'platforms@example.test']);

    $this->actingAs($demoting)
        ->patch($this->campaignUrl('operators/'.$demoted->getKey()), ['role' => 'staff'])
        ->assertSessionHasNoErrors();

    expect(linkStillOpens($theirs))->toBeFalse()
        ->and(linkStillOpens($demotersOwn))->toBeTrue()
        ->and(linkStillOpens($platforms))->toBeTrue()
        ->and($theirsUsed->fresh()?->accepted_at)->not->toBeNull();
});

test('a promotion withdraws nothing, and a refused step down withdraws nothing either', function (): void {
    // Withdrawal belongs to giving up authority, not to every change of role:
    // the Owner who promotes somebody gives up nothing, so their invitations
    // stand. **The promoted operator's own cannot be asked about**, and that is
    // measured rather than overlooked: withdrawing on every change of role
    // leaves this file green, because a Staff operator cannot send an
    // invitation and a demotion has already withdrawn any they sent as an
    // Owner -- no state the application produces gives that break anything to
    // withdraw.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $staff = User::factory()->create(['email' => 'helper@example.test']);

    $ownersInvitation = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'pending@example.test']);

    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$staff->getKey()), ['role' => 'owner'])
        ->assertSessionHasNoErrors();

    expect(linkStillOpens($ownersInvitation))->toBeTrue();

    // Now demote the new Owner back, leaving the original as the only one, and
    // have the original try to step down: refused, and their invitation,
    // withdrawn inside the transaction before the refusal, is restored by it.
    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$staff->getKey()), ['role' => 'staff'])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$owner->getKey()), ['role' => 'staff'])
        ->assertSessionHasErrors('operator');

    expect(linkStillOpens($ownersInvitation))->toBeTrue();
});

test('the form changes the role and nothing else, whatever else it carries', function (): void {
    // **The kickoff's finding 2.** `role` is not fillable on User, and every
    // writer assigns it by name; a roster form posting through a
    // mass-assigning update would be the defect Step 3 closed at acceptance,
    // arriving from a form an Owner uses. So the request carries other
    // columns, and none of them may land.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $staff = User::factory()->create(['name' => 'Kept Name', 'email' => 'kept@example.test']);

    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$staff->getKey()), [
            'role' => 'owner',
            'name' => 'Rewritten',
            'email' => 'rewritten@example.test',
            'password' => 'chosen-by-somebody-else',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $staff->fresh();

    expect($fresh?->role)->toBe(OperatorRole::Owner)
        ->and($fresh?->name)->toBe('Kept Name')
        ->and($fresh?->email)->toBe('kept@example.test')
        ->and(password_verify('password', (string) $fresh?->password))->toBeTrue();
});

test('the role must be one the product has', function (): void {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $this->actingAs($owner)
        ->patch($this->campaignUrl('operators/'.$staff->getKey()), ['role' => 'admin'])
        ->assertSessionHasErrors('role');

    expect($staff->fresh()?->role)->toBe(OperatorRole::Staff);
});
