<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\User;
use App\Operators\OperatorInvitationMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * A campaign inviting somebody (D-53 Axis 1 (i), D-56, D-58).
 *
 * **Two operators in every authority test, holding the two roles**, and every
 * deny paired with an allow through the same call (L-14): a policy denies an
 * ability it has never heard of exactly as it denies one it refused, so a
 * refusal alone would pass against a policy that was never wired.
 */

/**
 * The one message sent, and the invitation it belongs to.
 *
 * @return array{0: OperatorInvitationMessage, 1: string}
 */
function sentInvitation(): array
{
    $sent = Mail::sent(OperatorInvitationMessage::class);

    expect($sent)->toHaveCount(1);

    return [$sent->first(), (string) $sent->first()->render()];
}

test('an owner is shown the form, and staff are refused it', function (): void {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $this->actingAs($owner)->get($this->campaignUrl('operators/invite'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('operators/Invite'));

    $this->actingAs($staff)->get($this->campaignUrl('operators/invite'))->assertForbidden();
});

test('an owner invites somebody, and the row records who, with what authority, and on whose say', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    // An invitation already standing, so "the row it wrote" cannot be
    // satisfied by whichever row happens to be there.
    OperatorInvitation::factory()->create(['email' => 'earlier@example.test']);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'Ama.Boateng@Example.test', 'role' => 'owner'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    // Found by the address exactly as the inviter typed it: the invitation
    // keeps it that way, and the fold happens when it becomes an operator's
    // address and in every comparison.
    $row = DB::connection('tenant')->table('operator_invitations')->where('email', 'Ama.Boateng@Example.test')->sole();

    expect($row->role)->toBe('owner')
        ->and($row->invited_by_id)->toBe($owner->getKey())
        ->and($row->invited_by_label)->toBe('governor@example.test')
        ->and($row->token)->not->toBeNull()
        ->and($row->accepted_at)->toBeNull()
        ->and(DB::connection('tenant')->table('operator_invitations')->count())->toBe(2);
});

test('staff cannot invite anybody, and nothing is written or sent', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'owner'])
        ->assertForbidden();

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(0);
    Mail::assertNothingOutgoing();

    // **The allow, through the same call**, so the refusal above cannot pass
    // against a policy the gate never heard of.
    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(1);
});

test('the invitation is sent in the request, never queued, because nothing runs a queue', function (): void {
    // **Deferral 25's guard for this module.** No environment this product has
    // run in has a worker, so a queued invitation would sit in `jobs` while the
    // Owner was told it had been sent -- D-56's "an invitation the campaign
    // believes was sent and which never left". Both halves: the message was
    // sent rather than queued, and the class cannot be queued by accident.
    Mail::fake();

    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(OperatorInvitationMessage::class, fn ($message) => $message->hasTo('friend@example.test'));
    Mail::assertNothingQueued();

    expect(is_subclass_of(OperatorInvitationMessage::class, ShouldQueue::class))->toBeFalse()
        // And nothing reached the central queue table by another route.
        ->and(DB::connection('pgsql')->table('jobs')->count())->toBe(0);
});

test('the link in the message opens the invitation it belongs to, on this campaign\'s host', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create();
    OperatorInvitation::factory()->create(['email' => 'earlier@example.test']);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'owner'])
        ->assertSessionHasNoErrors();

    [, $body] = sentInvitation();

    $token = (string) DB::connection('tenant')->table('operator_invitations')->where('email', 'friend@example.test')->value('token');
    $other = (string) DB::connection('tenant')->table('operator_invitations')->where('email', 'earlier@example.test')->value('token');

    // This invitation's credential, and not the other one standing beside it,
    // on this campaign's own hostname -- the root CampaignHostTenancyBootstrapper
    // forces, which is the host and port a person's browser reaches it on.
    $link = route('invitation.show', ['invitation' => $token]);

    expect(parse_url($link, PHP_URL_HOST))->toBe($this->campaign->domains()->value('domain'))
        ->and($body)->toContain($link)
        ->and($body)->not->toContain($other)
        ->and($body)->toContain($this->campaign->name)
        ->and($body)->toContain('as an Owner');

    // And the link does what the message says.
    auth()->logout();

    $this->get($this->campaignUrl('invitation/'.$token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('email', 'friend@example.test'));
});

test('a staff invitation says nothing about governing the campaign', function (): void {
    Mail::fake();

    $this->actingAs(User::factory()->owner()->create())
        ->post($this->campaignUrl('operators/invite'), ['email' => 'helper@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    [, $body] = sentInvitation();

    expect($body)->not->toContain('as an Owner');
});

test('somebody already on the roster cannot be invited, whatever the casing', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    User::factory()->create(['email' => 'already@example.test']);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'Already@Example.test', 'role' => 'owner'])
        ->assertSessionHasErrors('email');

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(0);
    Mail::assertNothingOutgoing();

    // **The positive half, in the same run**, so the refusal cannot pass
    // against a rule that refuses every address.
    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'newcomer@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    expect(DB::connection('tenant')->table('operator_invitations')->pluck('email')->all())->toBe(['newcomer@example.test']);
});

test('somebody with an invitation still open cannot be sent a second, whatever the casing', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create();
    OperatorInvitation::factory()->create(['email' => 'waiting@example.test', 'role' => OperatorRole::Staff]);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'Waiting@Example.test', 'role' => 'owner'])
        ->assertSessionHasErrors('email');

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(1);
    Mail::assertNothingOutgoing();

    // **The positive half**: once that invitation is used, the same person may
    // be invited again -- the partial index's reason for being partial.
    DB::connection('tenant')->table('operator_invitations')->update(['token' => null, 'accepted_at' => now()]);
    User::query()->where('email', 'waiting@example.test')->delete();

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'Waiting@Example.test', 'role' => 'owner'])
        ->assertSessionHasNoErrors();

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(2);
});

test('the authority must be one the product has', function (): void {
    Mail::fake();

    $this->actingAs(User::factory()->owner()->create())
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'admin'])
        ->assertSessionHasErrors('role');

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(0);
});

test('a transport that refuses the message leaves no invitation, and the owner is told', function (): void {
    // **D-56's "must not be got wrong", run rather than argued**: the row and
    // the send succeed or fail together. A transport that refuses every
    // message stands in for an unreachable mail service.
    Mail::extend('refusing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection refused by probe transport.');
        }

        public function __toString(): string
        {
            return 'refusing://';
        }
    });

    config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing']);
    Mail::forgetMailers();

    $owner = User::factory()->owner()->create();
    // Standing before the attempt, so "no rows" cannot be satisfied by a
    // rollback that emptied the table.
    OperatorInvitation::factory()->create(['email' => 'earlier@example.test']);

    $response = $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'staff']);

    // The row first: a writer that kept it and swallowed the refusal would
    // otherwise fail on the missing message below and never reach this, which
    // is the claim the test exists for.
    expect(DB::connection('tenant')->table('operator_invitations')->pluck('email')->all())->toBe(['earlier@example.test']);

    $response->assertSessionHasErrors(['email' => 'The invitation to friend@example.test could not be sent, so none was recorded. Try again in a moment.']);
});

test('the form and the message both say how long the link works, and it is the model\'s lifetime', function (): void {
    // D-59's lifetime, told to both people it affects: the Owner sending it,
    // and the person who has to use it in time. Asserted against the number
    // the link actually enforces rather than a literal, so a lifetime changed
    // in one place cannot leave either sentence promising the old one.
    Mail::fake();

    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get($this->campaignUrl('operators/invite'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operators/Invite')
            ->where('lifetimeDays', OperatorInvitation::LIFETIME_DAYS));

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'friend@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    [, $body] = sentInvitation();

    expect($body)->toContain('The link works once, and only for '.OperatorInvitation::LIFETIME_DAYS.' days from when this message was sent.')
        ->and($body)->toContain('ask '.$this->campaign->name.' to invite you again');
});
