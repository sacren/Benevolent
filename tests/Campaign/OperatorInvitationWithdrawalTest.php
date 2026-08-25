<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * A campaign taking back an invitation nobody has used (D-57).
 *
 * **Two invitations in every fixture that withdraws one**, per the phase's §3
 * convention: a withdrawal of every live invitation, or of the first row in
 * the table, is the same act as the right one while only one exists.
 */

/**
 * An invitation's row as the database holds it, credential included.
 */
function invitationRow(OperatorInvitation $invitation): object
{
    return DB::connection('tenant')->table('operator_invitations')->where('id', $invitation->getKey())->sole();
}

test('an owner withdraws an invitation, which keeps its row and loses its link, and the one beside it stands', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);

    $bystander = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'bystander@example.test']);
    $withdrawn = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'withdrawn@example.test']);

    $token = (string) invitationRow($withdrawn)->token;

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/invitations/'.$withdrawn->getKey()))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    // The row is the record, and it survives: who was invited, by whom, as
    // what -- with neither a credential nor an acceptance.
    $row = invitationRow($withdrawn);

    expect($row->token)->toBeNull()
        ->and($row->accepted_at)->toBeNull()
        ->and($row->email)->toBe('withdrawn@example.test')
        ->and($row->invited_by_label)->toBe('governor@example.test')
        ->and(invitationRow($bystander)->token)->not->toBeNull();

    // And its link, which the invitee may still be holding, opens nothing.
    auth()->logout();

    $this->get($this->campaignUrl('invitation/'.$token))->assertNotFound();
});

test('staff cannot withdraw an invitation, and it still works', function (): void {
    // Paired with the test above through the same route (L-14): a policy with
    // no `delete` method refuses exactly as this does.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $invitation = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'waiting@example.test']);

    $this->actingAs($staff)
        ->delete($this->campaignUrl('operators/invitations/'.$invitation->getKey()))
        ->assertForbidden();

    expect(invitationRow($invitation)->token)->not->toBeNull();
});

test('an invitation already used is not withdrawn, and the owner is told so in words', function (): void {
    // By the time the Owner clicks, the invitee may have accepted it a moment
    // earlier. That is news, not a fault, and the acceptance stands.
    $owner = User::factory()->owner()->create();

    $used = OperatorInvitation::factory()->invitedBy($owner)->accepted()->create(['email' => 'arrived@example.test']);
    $acceptedAt = invitationRow($used)->accepted_at;

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/invitations/'.$used->getKey()))
        ->assertRedirect(route('operators.index'))
        ->assertSessionHasErrors('invitation');

    expect(invitationRow($used)->accepted_at)->toBe($acceptedAt);
});

test('withdrawing a lost invitation lets the same person be invited again', function (): void {
    // The reason withdrawal is the remedy for an invitation that never
    // arrived: the inviting writer refuses a second live invitation to one
    // address, and only the first one's withdrawal lifts that.
    Mail::fake();

    $owner = User::factory()->owner()->create();

    $lost = OperatorInvitation::factory()->invitedBy($owner)->create(['email' => 'lost@example.test']);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'lost@example.test', 'role' => 'staff'])
        ->assertSessionHasErrors('email');

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/invitations/'.$lost->getKey()))
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'lost@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    expect(DB::connection('tenant')->table('operator_invitations')->where('email', 'lost@example.test')->whereNotNull('token')->count())->toBe(1)
        ->and(DB::connection('tenant')->table('operator_invitations')->where('email', 'lost@example.test')->count())->toBe(2);
});
