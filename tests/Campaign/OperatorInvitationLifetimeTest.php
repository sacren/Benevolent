<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\User;
use App\Operators\AcceptOperatorInvitation;
use App\Operators\OperatorInvitationMessage;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * How long an invitation stands (D-59): seven days from when it was sent,
 * compared against `created_at` wherever "live" is asked, and never stored or
 * scheduled.
 *
 * **Three places ask, and this file holds them to one answer**: the link, the
 * acceptance claim, and the inviting writer. Phase 6 Step 5 measured what one
 * of them answering alone does -- the invitee refused while the Owner is told
 * the address "already has an invitation that has not been used", and the
 * partial index refusing a fresh one at 23505.
 *
 * **Every fixture holds an invitation just inside the lifetime beside the one
 * just outside it**, so "the expired one was refused" cannot be satisfied by
 * a rule refusing every invitation, and "the fresh one works" cannot be
 * satisfied by a rule refusing none.
 */

beforeEach(function (): void {
    // The `invitation` limiter is keyed on the caller, platform-wide (L-24),
    // so its counter survives between tests in one process.
    app('cache')->driver()->flush();
});

/**
 * The credential an invitation holds, read from the column that minted it.
 */
function lifetimeTokenOf(OperatorInvitation $invitation): string
{
    return (string) DB::connection('tenant')->table('operator_invitations')
        ->where('id', $invitation->getKey())->value('token');
}

/**
 * An invitation a minute short of its lifetime, and one a minute past it.
 *
 * @return array{0: OperatorInvitation, 1: OperatorInvitation}
 */
function insideAndPastTheLifetime(): array
{
    $inside = OperatorInvitation::factory()
        ->sentAgo(CarbonInterval::minutes(OperatorInvitation::LIFETIME_DAYS * 24 * 60 - 1))
        ->create(['email' => 'still-in-time@example.test']);

    $past = OperatorInvitation::factory()->sentAgo()->create(['email' => 'too-late@example.test']);

    return [$inside, $past];
}

/**
 * What somebody accepting an invitation fills in.
 *
 * @return array<string, string>
 */
function lifetimeAcceptanceDetails(): array
{
    return [
        'name' => 'Ama Boateng',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ];
}

test('an invitation\'s link opens inside its lifetime and not after it', function (): void {
    [$inside, $past] = insideAndPastTheLifetime();

    $this->get($this->campaignUrl('invitation/'.lifetimeTokenOf($past)))->assertNotFound();

    $this->get($this->campaignUrl('invitation/'.lifetimeTokenOf($inside)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Invitation')->where('email', 'still-in-time@example.test'));
});

test('an expired invitation creates nobody, and opening it writes nothing', function (): void {
    [$inside, $past] = insideAndPastTheLifetime();
    $pastToken = lifetimeTokenOf($past);

    $this->post($this->campaignUrl('invitation/'.$pastToken), lifetimeAcceptanceDetails())->assertNotFound();

    expect(User::query()->count())->toBe(0)
        // **Refused at read and left alone**: its credential is untouched, so
        // nothing a mail scanner's prefetch or a stale click does changes the
        // record. Only a withdrawal -- an Owner's, or the inviting writer's
        // -- takes it.
        ->and(lifetimeTokenOf($past->refresh()))->toBe($pastToken)
        ->and($past->accepted_at)->toBeNull();

    // **The positive half in the same run**: the invitation still inside its
    // lifetime admits its invitee.
    $this->post($this->campaignUrl('invitation/'.lifetimeTokenOf($inside)), lifetimeAcceptanceDetails())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(User::query()->pluck('email')->all())->toBe(['still-in-time@example.test']);
});

test('the acceptance claim refuses an invitation whose lifetime ran out after its page opened', function (): void {
    // The link's binding and the claim run in the same request, at the same
    // moment, so HTTP cannot put a lifetime's end between them. The writer is
    // asked directly, holding the model a binding would have handed it.
    [$inside, $past] = insideAndPastTheLifetime();
    $accept = app(AcceptOperatorInvitation::class);

    $past->token = lifetimeTokenOf($past);

    expect(fn () => $accept($past, ['name' => 'Too Late', 'password' => 'a-memorable-passphrase']))
        ->toThrow(NotFoundHttpException::class);

    expect(User::query()->count())->toBe(0)
        ->and(DB::connection('tenant')->table('operator_invitations')->where('id', $past->getKey())->value('token'))->not->toBeNull();

    $inside->token = lifetimeTokenOf($inside);

    expect($accept($inside, ['name' => 'In Time', 'password' => 'a-memorable-passphrase'])->email)->toBe('still-in-time@example.test');
});

test('an expired invitation does not stand in the way of inviting that person again, and inviting withdraws it', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create();
    [$inside, $past] = insideAndPastTheLifetime();
    // A second expired invitation, to somebody else: re-inviting one person
    // must not withdraw anybody else's.
    $otherPast = OperatorInvitation::factory()->sentAgo()->create(['email' => 'also-late@example.test']);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'Too-Late@Example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    $rows = DB::connection('tenant')->table('operator_invitations')->orderBy('id')->get()
        ->map(fn (object $row): array => [$row->email, $row->token !== null, $row->accepted_at !== null])->all();

    expect($rows)->toBe([
        ['still-in-time@example.test', true, false],
        // Withdrawn, and kept (D-57): no credential and no acceptance.
        ['too-late@example.test', false, false],
        ['also-late@example.test', true, false],
        // The fresh invitation, in the casing it was typed.
        ['Too-Late@Example.test', true, false],
    ]);

    Mail::assertSent(OperatorInvitationMessage::class, 1);
});

test('an invitation still inside its lifetime still stands in the way', function (): void {
    Mail::fake();

    $owner = User::factory()->owner()->create();
    insideAndPastTheLifetime();

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'Still-In-Time@Example.test', 'role' => 'owner'])
        ->assertSessionHasErrors(['email' => 'Still-In-Time@Example.test already has an invitation to this campaign that has not been used.']);

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(2)
        ->and(DB::connection('tenant')->table('operator_invitations')->whereNotNull('token')->count())->toBe(2);

    Mail::assertNothingOutgoing();
});

test('an owner can still withdraw an expired invitation, which the roster reaches by id', function (): void {
    $owner = User::factory()->owner()->create();
    [$inside, $past] = insideAndPastTheLifetime();

    $this->actingAs($owner)
        ->delete($this->campaignUrl('operators/invitations/'.$past->getKey()))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    expect(DB::connection('tenant')->table('operator_invitations')->where('id', $past->getKey())->value('token'))->toBeNull()
        ->and(lifetimeTokenOf($inside))->not->toBe('');
});

test('a transport that refuses the fresh invitation leaves the expired one as it was', function (): void {
    // The withdrawal and the fresh row share InviteOperator's transaction, so
    // a send that fails undoes both rather than leaving the person with no
    // invitation at all and the Owner told nothing was recorded.
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
    [$inside, $past] = insideAndPastTheLifetime();
    $pastToken = lifetimeTokenOf($past);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'too-late@example.test', 'role' => 'staff'])
        ->assertSessionHasErrors('email');

    expect(DB::connection('tenant')->table('operator_invitations')->orderBy('id')->pluck('token')->all())
        ->toBe([lifetimeTokenOf($inside), $pastToken]);
});
