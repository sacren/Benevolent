<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\OperatorRole;
use App\Models\AuditEntry;
use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
 * Somebody a campaign invited becoming one of its operators (D-53 Axis 1 (i),
 * D-54, D-60, D-61).
 *
 * **Every fixture holds two invitations, and each assertion names the one the
 * writer must not have touched** -- the phase's §3 convention. "The right
 * invitation was spent" is vacuous against a fixture holding one, since the
 * right one and any one are then the same row.
 *
 * Cross-campaign isolation is tests/Tenancy/CampaignInvitationTest.php's, which
 * provisions two campaigns; this file runs in one.
 */

beforeEach(function (): void {
    // The `invitation` limiter is keyed on the caller and deliberately not on
    // the campaign (L-24), so its counter survives between tests in one
    // process. Reached through the manager's driver() because that is the
    // untagged store the framework's own limiter holds (L-24, L-27).
    app('cache')->driver()->flush();
});

/**
 * A live invitation's token, read back from the column that minted it.
 */
function liveTokenOf(OperatorInvitation $invitation): string
{
    return (string) DB::connection('tenant')->table('operator_invitations')
        ->where('id', $invitation->getKey())->value('token');
}

/**
 * What somebody accepting an invitation fills in.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function acceptanceDetails(array $extra = []): array
{
    return [
        'name' => 'Ama Boateng',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
        ...$extra,
    ];
}

test('somebody with no account can open their invitation, and it names them', function (): void {
    $theirs = OperatorInvitation::factory()->owner()->create(['email' => 'Ama.Boateng@Example.test']);
    OperatorInvitation::factory()->create(['email' => 'somebody.else@example.test']);

    $this->assertGuest();

    $this->get($this->campaignUrl('invitation/'.liveTokenOf($theirs)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Invitation')
            // Exactly as it was invited, and the one this link opens rather
            // than the other outstanding invitation.
            ->where('email', 'Ama.Boateng@Example.test')
            ->where('role', 'owner')
            ->where('campaignName', $this->campaign->name)
            // Nothing a shell could read, because nobody is signed in.
            ->where('auth.user', null)
            ->where('auth.permissions', []));

    $this->assertGuest();
});

test('the routes are public, credential-shaped and metered, which is what the requests here depend on', function (): void {
    // The configuration invariant behind the behaviour (L-14's pairing), and
    // the unsubscribe routes' shape exactly.
    foreach (['invitation.show', 'invitation.accept'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        $middleware = $route->gatherMiddleware();

        expect($middleware)->not->toContain('auth')
            ->and($middleware)->not->toContain('verified')
            ->and($middleware)->toContain('tenant')
            ->and($middleware)->toContain('guest')
            ->and($middleware)->toContain('throttle:invitation');

        // Without this the `uuid` column turns a mistyped link into SQLSTATE
        // 22P02 -- a 500 for somebody who has no account, with what they typed
        // inlined into the exception message.
        expect($route->wheres)->toHaveKey('invitation');
    }
});

test('accepting creates the operator the invitation named, with its authority, and spends only that invitation', function (): void {
    $theirs = OperatorInvitation::factory()->owner()->create(['email' => 'Ama.Boateng@Example.test']);
    $other = OperatorInvitation::factory()->create(['email' => 'somebody.else@example.test']);
    $otherToken = liveTokenOf($other);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($theirs)), acceptanceDetails())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $operator = User::query()->sole();

    // The invitation's address, folded (D-61): Fortify lowercases what is typed
    // at sign-in and compares exactly, so this is the only form that can sign in.
    expect($operator->email)->toBe('ama.boateng@example.test')
        ->and($operator->name)->toBe('Ama Boateng')
        ->and($operator->role)->toBe(OperatorRole::Owner)
        // D-60: the link reached them at this address, which is the proof.
        ->and($operator->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($operator);

    // Spent in the way the schema allows and no other: no credential, and a
    // time it was used.
    $spent = DB::connection('tenant')->table('operator_invitations')->where('id', $theirs->getKey())->first();

    expect($spent->token)->toBeNull()
        ->and($spent->accepted_at)->not->toBeNull()
        // The address stays as the inviter typed it; only the operator's copy
        // is folded.
        ->and($spent->email)->toBe('Ama.Boateng@Example.test');

    // **The row the writer must not have touched.**
    $untouched = DB::connection('tenant')->table('operator_invitations')->where('id', $other->getKey())->first();

    expect($untouched->token)->toBe($otherToken)
        ->and($untouched->accepted_at)->toBeNull();
});

test('the authority is the invitation\'s, whatever the accepter posts', function (): void {
    // The escalation the whole design is arranged to close, through the real
    // endpoint: a Staff invitation, a request naming Owner. An Owner invitation
    // sits beside it so that "joins as Staff" cannot be satisfied by a writer
    // that ignores invitations and hands out the default.
    $staff = OperatorInvitation::factory()->create(['email' => 'staff@example.test']);
    $owner = OperatorInvitation::factory()->owner()->create(['email' => 'owner@example.test']);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($staff)), acceptanceDetails(['role' => OperatorRole::Owner->value]))->assertRedirect();
    auth()->logout();
    $this->post($this->campaignUrl('invitation/'.liveTokenOf($owner)), acceptanceDetails(['role' => OperatorRole::Staff->value]))->assertRedirect();

    expect(User::query()->where('email', 'staff@example.test')->sole()->role)->toBe(OperatorRole::Staff)
        ->and(User::query()->where('email', 'owner@example.test')->sole()->role)->toBe(OperatorRole::Owner);
});

test('the request cannot choose the address it joins as', function (): void {
    $theirs = OperatorInvitation::factory()->create(['email' => 'invited@example.test']);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($theirs)), acceptanceDetails(['email' => 'substituted@example.test']))->assertRedirect();

    expect(User::query()->pluck('email')->all())->toBe(['invited@example.test']);
});

test('a link that has been used opens nothing and creates nothing', function (): void {
    $used = OperatorInvitation::factory()->create(['email' => 'first@example.test']);
    $token = liveTokenOf($used);
    OperatorInvitation::factory()->create(['email' => 'second@example.test']);

    $this->post($this->campaignUrl('invitation/'.$token), acceptanceDetails())->assertRedirect();
    auth()->logout();

    // Spent, so the link finds no row: the single-use property is the absence
    // of the credential, not a status somebody compares.
    $this->get($this->campaignUrl('invitation/'.$token))->assertNotFound();
    $this->post($this->campaignUrl('invitation/'.$token), acceptanceDetails(['name' => 'Second Go']))->assertNotFound();

    expect(User::query()->count())->toBe(1);
});

test('a link nobody was sent, or one mistyped, is a 404 and never a 500', function (): void {
    OperatorInvitation::factory()->count(2)->create();

    $unknown = '11111111-2222-4333-8444-555555555555';

    $this->get($this->campaignUrl('invitation/'.$unknown))->assertNotFound();
    $this->post($this->campaignUrl('invitation/'.$unknown), acceptanceDetails())->assertNotFound();

    // Not a uuid: the router refuses it before a query is built.
    $this->get($this->campaignUrl('invitation/not-a-uuid'))->assertNotFound();
    $this->post($this->campaignUrl('invitation/'.'not-a-uuid'), acceptanceDetails())->assertNotFound();

    expect(User::query()->count())->toBe(0);
});

test('an invitation never creates a second account for somebody who already is an operator', function (): void {
    // D-61's "must not be got wrong". Stored with a capital, the way a row
    // written before D-61 could be, so a writer comparing exactly would miss it.
    User::factory()->create(['email' => 'Already@Example.test']);
    $theirs = OperatorInvitation::factory()->create(['email' => 'already@example.test']);
    $other = OperatorInvitation::factory()->create(['email' => 'newcomer@example.test']);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($theirs)), acceptanceDetails())->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(User::query()->count())->toBe(1)
        // Refused before it was spent, so the link still works for whatever
        // the campaign decides to do about it.
        ->and(DB::connection('tenant')->table('operator_invitations')->where('id', $theirs->getKey())->value('token'))->not->toBeNull()
        ->and(DB::connection('tenant')->table('operator_invitations')->where('id', $other->getKey())->value('token'))->not->toBeNull();

    // **And the positive half in the same run**, so this cannot pass against a
    // writer that refuses everybody.
    $this->post($this->campaignUrl('invitation/'.liveTokenOf($other)), acceptanceDetails())->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe(2);
});

test('the trail records the operator who arrived, and names no actor because nobody signed in did it', function (): void {
    $inviter = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $theirs = OperatorInvitation::factory()->invitedBy($inviter)->create(['email' => 'newcomer@example.test']);
    OperatorInvitation::factory()->invitedBy($inviter)->owner()->create(['email' => 'bystander@example.test']);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($theirs)), acceptanceDetails())->assertRedirect();

    $entry = AuditEntry::query()->where('subject_label', 'newcomer@example.test')->sole();

    expect($entry->event)->toBe(AuditEvent::OperatorRegistered)
        ->and($entry->changes)->toBe(['role' => ['from' => null, 'to' => 'staff']])
        // Unauthenticated at the moment they are created, so no actor -- which
        // is why the invitation row, naming its inviter, outlives acceptance.
        ->and($entry->actor_id)->toBeNull();

    expect(OperatorInvitation::query()->find($theirs->getKey())?->invited_by_label)->toBe('governor@example.test');
});

test('the password an accepter chooses never reaches the database as they typed it', function (): void {
    // Through the production writer, read with the query builder rather than
    // the model: a factory hands the model an already-hashed value, and an
    // accessor could make any storage look like anything.
    $theirs = OperatorInvitation::factory()->create(['email' => 'ada@example.test']);
    OperatorInvitation::factory()->create(['email' => 'grace@example.test']);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($theirs)), acceptanceDetails())->assertRedirect();

    $stored = (string) DB::table('users')->where('email', 'ada@example.test')->value('password');

    expect($stored)->not->toBe('a-memorable-passphrase')
        ->and(Hash::check('a-memorable-passphrase', $stored))->toBeTrue();
});

test('an operator already signed in is not handed somebody else\'s invitation', function (): void {
    // `guest`, Fortify's own answer for its registration route: accepting
    // signs the new operator in, and doing that over an existing session would
    // swap one operator for another in the middle of their work.
    $signedIn = User::factory()->create(['email' => 'working@example.test']);
    $theirs = OperatorInvitation::factory()->create(['email' => 'newcomer@example.test']);

    $this->actingAs($signedIn);

    $this->post($this->campaignUrl('invitation/'.liveTokenOf($theirs)), acceptanceDetails())->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($signedIn);

    expect(User::query()->count())->toBe(1)
        ->and(DB::connection('tenant')->table('operator_invitations')->where('id', $theirs->getKey())->value('token'))->not->toBeNull();
});

test('the endpoint is metered, and the budget can actually be crossed', function (): void {
    // L-23: spend enough to cross the threshold named. Ten a minute, so the
    // eleventh is the first that can be refused; an unknown token keeps each
    // request cheap and is the enumeration case the limit exists for.
    $unknown = '11111111-2222-4333-8444-555555555555';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->get($this->campaignUrl('invitation/'.$unknown))->assertNotFound();
    }

    $this->get($this->campaignUrl('invitation/'.$unknown))->assertStatus(429);
});
