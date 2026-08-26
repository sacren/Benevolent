<?php

declare(strict_types=1);

use App\Models\User;
use App\Operators\OperatorInvitationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * How much mail one Owner can make the platform send by inviting people (D-59).
 *
 * Measured before the limit existed: one Owner's session sent 60 invitations
 * to 60 addresses in one burst, with nothing refusing any of them. The key
 * names a person, within one campaign (L-24); tests/Tenancy holds the half
 * that needs two campaigns.
 *
 * **Each test holds two of whatever its assertion tells apart.** Where the
 * question is whose budget, two Owners, so "this Owner is refused" cannot be
 * satisfied by a limit that refuses the whole campaign. Where it is how many
 * or how long, the attempt inside the budget beside the one past it -- the
 * twentieth and the twenty-first, the same hour and the next.
 */

beforeEach(function (): void {
    // The framework's limiter reaches the cache through the manager's
    // driver(), outside the tenancy wrapper's tags (L-24, L-27), so its
    // counters survive between tests in one process.
    app('cache')->driver()->flush();

    Mail::fake();
});

/**
 * Invite this many distinct people as this Owner, and return the responses.
 *
 * @return list<TestResponse>
 */
function inviteDistinctPeople(TestCase $test, User $owner, string $url, string $prefix, int $count): array
{
    $responses = [];

    for ($i = 1; $i <= $count; $i++) {
        $responses[] = $test->actingAs($owner)
            ->post($url, ['email' => "{$prefix}{$i}@example.test", 'role' => 'staff']);
    }

    return $responses;
}

test('sending is metered and the form is not, which is what the requests here depend on', function (): void {
    // The configuration invariant behind the behaviour (L-14's pairing).
    expect(Route::getRoutes()->getByName('operators.invitations.store')?->gatherMiddleware())->toContain('throttle:invite-operators')
        ->and(Route::getRoutes()->getByName('operators.invitations.create')?->gatherMiddleware())->not->toContain('throttle:invite-operators');
});

test('an owner may send twenty invitations in an hour, and the twenty-first is refused in words', function (): void {
    $owner = User::factory()->owner()->create();

    // The whole budget, spent: every one of these must be sent, so the limit
    // below is crossed rather than approached (L-23).
    foreach (inviteDistinctPeople($this, $owner, $this->campaignUrl('operators/invite'), 'invited', 20) as $response) {
        $response->assertSessionHasNoErrors()->assertRedirect(route('operators.index'));
    }

    $refused = $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'one-too-many@example.test', 'role' => 'staff']);

    // The row first: a limit that wrote the invitation and then complained
    // would otherwise fail on the message below and never reach this.
    expect(DB::connection('tenant')->table('operator_invitations')->where('email', 'one-too-many@example.test')->exists())->toBeFalse()
        ->and(DB::connection('tenant')->table('operator_invitations')->count())->toBe(20);

    Mail::assertSent(OperatorInvitationMessage::class, 20);

    $refused->assertStatus(302)
        ->assertSessionHasErrors(['email' => 'You have sent as many invitations as one person may in an hour. Try again in 60 minutes.']);
});

test('one owner spending their budget leaves another owner of the same campaign theirs', function (): void {
    $spent = User::factory()->owner()->create();
    $other = User::factory()->owner()->create();

    inviteDistinctPeople($this, $spent, $this->campaignUrl('operators/invite'), 'first', 20);

    $this->actingAs($spent)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'refused@example.test', 'role' => 'staff'])
        ->assertSessionHasErrors('email');

    $this->actingAs($other)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'welcome@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('operators.index'));

    expect(DB::connection('tenant')->table('operator_invitations')->where('email', 'welcome@example.test')->exists())->toBeTrue();
});

test('the budget is an hour, and it comes back', function (): void {
    $owner = User::factory()->owner()->create();

    inviteDistinctPeople($this, $owner, $this->campaignUrl('operators/invite'), 'early', 20);

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'still-too-soon@example.test', 'role' => 'staff'])
        ->assertSessionHasErrors('email');

    $this->travel(61)->minutes();

    $this->actingAs($owner)
        ->post($this->campaignUrl('operators/invite'), ['email' => 'next-hour@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();
});
