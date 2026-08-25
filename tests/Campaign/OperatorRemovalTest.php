<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Models\AuditEntry;
use App\Models\OperatorInvitation;
use App\Models\User;
use App\Operators\RemoveOperator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/*
 * An Owner removing somebody else from the roster (D-57, deferral 16).
 *
 * **Removal by somebody else is what gives the act a second party**, and
 * deferral 16 was deferred on a ground written about self-removal alone: that
 * deleting a departing operator's reset token "would buy nothing", because the
 * trail keeps their address anyway. This file asks what the second party
 * changes, by running a removal through the surface and measuring what is left
 * -- never by reading the schema -- and whether anything the removed operator
 * still holds lets them back in.
 */

test('an owner removes somebody, the one beside them stays, and the trail names the owner who did it', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $removed = User::factory()->create(['email' => 'removed@example.test']);
    $staying = User::factory()->create(['email' => 'staying@example.test']);

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/'.$removed->getKey()))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    expect(User::query()->orderBy('email')->pluck('email')->all())
        ->toBe(['governor@example.test', 'staying@example.test']);

    // **The actor the self-removal entry cannot have.** Leaving signs the
    // operator out before their row goes, so that entry names nobody; this
    // request stays authenticated throughout, so the same observer names the
    // Owner with no change of its own.
    $entry = AuditEntry::query()->where('event', AuditEvent::OperatorRemoved->value)->sole();

    expect($entry->subject_id)->toBe($removed->getKey())
        ->and($entry->subject_label)->toBe('removed@example.test')
        ->and($entry->actor_id)->toBe($owner->getKey())
        ->and($entry->actor_label)->toBe('governor@example.test')
        ->and($staying->fresh())->not->toBeNull();
});

test('staff cannot remove anybody, and an owner cannot remove themselves from the roster', function (): void {
    // Paired with the test above through the same route (L-14), and the
    // second refusal is the one a policy allowing every Owner would miss:
    // leaving is the profile page's act, with its password and its sign-out.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $colleague = User::factory()->create();

    $this->actingAs($staff)
        ->delete($this->campaignUrl('operators/'.$colleague->getKey()))
        ->assertForbidden();

    User::factory()->owner()->create();

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/'.$owner->getKey()))
        ->assertForbidden();

    expect($colleague->fresh())->not->toBeNull()
        ->and($owner->fresh())->not->toBeNull();
});

test('an owner removing another owner withdraws the invitations the removed owner sent, and no others', function (): void {
    $remover = User::factory()->owner()->create(['email' => 'remover@example.test']);
    $removed = User::factory()->owner()->create(['email' => 'removed@example.test']);

    $theirs = OperatorInvitation::factory()->invitedBy($removed)->create(['email' => 'theirs@example.test']);
    $removersOwn = OperatorInvitation::factory()->invitedBy($remover)->create(['email' => 'mine@example.test']);

    $this->actingAs($remover)
        ->delete($this->campaignUrl('operators/'.$removed->getKey()))
        ->assertSessionHasNoErrors();

    expect($theirs->fresh()?->token)->toBeNull()
        ->and($theirs->fresh()?->accepted_at)->toBeNull()
        ->and($removersOwn->fresh()?->token)->not->toBeNull();
});

test('what a removal by somebody else leaves behind is what leaving leaves, plus the name of who removed them', function (): void {
    // **Deferral 16, asked by running a deletion** (D-55's instrument). The
    // removed operator is given every home Step 2 found: the invitation that
    // admitted them, one they sent, a reset token, and the trail's own entry.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);

    OperatorInvitation::factory()->invitedBy($owner)->accepted()->create(['email' => 'removed@example.test']);
    $removed = User::factory()->owner()->create(['email' => 'removed@example.test']);
    OperatorInvitation::factory()->invitedBy($removed)->create(['email' => 'newcomer@example.test']);

    Password::broker()->createToken($removed);

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/'.$removed->getKey()))
        ->assertSessionHasNoErrors();

    // The same four homes a departure through the profile page leaves -- the
    // decision Phase 0 recorded, removal being an access change, now reached
    // by a second path. `users.email` is the only one emptied.
    expect(campaignColumnsHolding('removed@example.test'))->toBe([
        'audit_entries.subject_label',
        'operator_invitations.email',
        'operator_invitations.invited_by_label',
        'password_reset_tokens.email',
    ]);

    // **What the second party adds is the remover's name, and it is theirs,
    // not the removed operator's.** The Owner who acted is recorded as the
    // actor of the removal, beside the homes they already had.
    expect(campaignColumnsHolding('governor@example.test'))->toBe([
        'audit_entries.actor_label',
        'audit_entries.subject_label',
        'operator_invitations.invited_by_label',
        'users.email',
    ]);
});

test('a reset token the removed operator still holds lets nobody back in, while a staying operator\'s still works', function (): void {
    // **The credential half of deferral 16.** Its reset row outlives them, as
    // the scan above shows. What decides whether that is a defect is whether
    // anything can be done with it: the broker finds the account by address,
    // and there is no account to find.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $removed = User::factory()->create(['email' => 'removed@example.test']);
    $staying = User::factory()->create(['email' => 'staying@example.test']);

    $removedToken = Password::broker()->createToken($removed);
    $stayingToken = Password::broker()->createToken($staying);

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/'.$removed->getKey()))
        ->assertSessionHasNoErrors();

    Auth::logout();

    $this->post($this->campaignUrl('reset-password'), [
        'token' => $removedToken,
        'email' => 'removed@example.test',
        'password' => 'a-new-passphrase-entirely',
        'password_confirmation' => 'a-new-passphrase-entirely',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'removed@example.test')->exists())->toBeFalse();

    // **The positive half through the same route**, without which the refusal
    // above is satisfied by a reset flow that works for nobody.
    $this->post($this->campaignUrl('reset-password'), [
        'token' => $stayingToken,
        'email' => 'staying@example.test',
        'password' => 'a-new-passphrase-entirely',
        'password_confirmation' => 'a-new-passphrase-entirely',
    ])->assertSessionHasNoErrors();

    expect(password_verify('a-new-passphrase-entirely', (string) $staying->fresh()?->password))->toBeTrue();
});

test('a removed operator\'s open session stops working at their next request', function (): void {
    // Removal happens in the Owner's request; the removed operator may be
    // signed in elsewhere at that moment. Signed in for real here, through the
    // login route, because actingAs() binds the model straight into the guard
    // and would never look them up again.
    User::factory()->owner()->create(['email' => 'governor@example.test']);
    $removed = User::factory()->create(['email' => 'removed@example.test']);

    $this->post($this->campaignUrl('login'), ['email' => 'removed@example.test', 'password' => 'password'])
        ->assertRedirect();

    // The positive half: the session works before the removal.
    Auth::forgetGuards();
    $this->get($this->campaignUrl('dashboard'))->assertOk();

    // The Owner's act, in the Owner's request.
    app(RemoveOperator::class)($removed, 'refused');

    Auth::forgetGuards();

    $this->get($this->campaignUrl('dashboard'))->assertRedirectContains('/login');
    $this->assertGuest();
});
