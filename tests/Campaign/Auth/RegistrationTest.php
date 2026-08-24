<?php

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
 * Nobody becomes an operator of a campaign by asking to (D-53, Axis 2 (a)).
 *
 * Registration is closed on every campaign: a campaign's first Owner is invited
 * by the platform (`campaign:invite-owner`) and everybody after by the
 * campaign's own Owners, and both arrive through an invitation.
 *
 * **Nothing in this file can skip, and that is the point of how it is
 * written.** It used to open with `skipUnlessFortifyHas(Features::registration())`,
 * which would have turned every test here green-to-skipped the moment
 * registration closed -- including the only two that proved a stranger could
 * not claim authority. A guard that goes quiet at exactly the moment the
 * behaviour it describes changes is the shape Blueprint §5 names for browser
 * tests, and Step 1 measured it arriving here: closing registration outright
 * turned seven tests green-to-skipped. So these assert the closed state
 * directly, and the proof that the escalation cannot happen now lives with the
 * only path that creates an operator (tests/Campaign/InvitationAcceptanceTest.php,
 * "the authority is the invitation's, whatever the accepter posts").
 */

test('the application does not offer registration at all', function (): void {
    // The configuration invariant behind every behaviour below (L-14's
    // pairing): the feature is off and its routes do not exist.
    expect(Features::enabled(Features::registration()))->toBeFalse()
        ->and(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();
});

test('there is no registration page to open', function (): void {
    $this->get($this->campaignUrl('register'))->assertNotFound();

    $this->assertGuest();
});

test('a stranger posting to an established campaign creates nothing', function (): void {
    // The measured defect D-53 exists to close: an unauthenticated POST on a
    // campaign that already had an Owner used to create a Staff operator, who
    // could read the whole supporter list at once. Two operators in the
    // fixture, so a count that stays at two is the claim, not an empty table.
    User::factory()->owner()->create(['email' => 'incumbent@example.test']);
    User::factory()->create(['email' => 'colleague@example.test']);

    $this->post($this->campaignUrl('register'), [
        'name' => 'Mallory Vance',
        'email' => 'stranger@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => OperatorRole::Owner->value,
    ])->assertNotFound();

    $this->assertGuest();

    expect(User::query()->orderBy('email')->pluck('email')->all())
        ->toBe(['colleague@example.test', 'incumbent@example.test']);
});

test('a stranger cannot claim a campaign nobody has claimed yet', function (): void {
    // **Deferral 7's case, and the reason it closes rather than moves.** On an
    // empty campaign the first registrant used to become Owner, on a check and
    // an insert that were not atomic, so two racing registrants both did. There
    // is no such path now: the first Owner is the person the platform invited.
    $invited = OperatorInvitation::factory()->owner()->create(['email' => 'director@example.test']);

    $this->post($this->campaignUrl('register'), [
        'name' => 'First Arrival',
        'email' => 'first@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(0);

    // **The positive half, through the path that replaced it**, in the same
    // run: the invited person does become the campaign's Owner, so the refusal
    // above is a closed door rather than a campaign nobody can enter.
    $token = (string) DB::connection('tenant')->table('operator_invitations')->where('id', $invited->getKey())->value('token');

    $this->post($this->campaignUrl('invitation/'.$token), [
        'name' => 'Campaign Director',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ])->assertRedirect();

    expect(User::query()->sole())
        ->email->toBe('director@example.test')
        ->role->toBe(OperatorRole::Owner);
});
