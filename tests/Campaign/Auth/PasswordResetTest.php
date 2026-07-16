<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('requesting a reset link is metered per operator', function () {
    Notification::fake();

    $ada = User::factory()->create(['email' => 'ada@example.test']);
    $bo = User::factory()->create(['email' => 'bo@example.test']);

    // The only unauthenticated endpoint that makes the platform send mail, and
    // it carried no budget at all before this: Fortify's `limiters` config
    // reaches login, the two-factor challenge, passkeys and email verification,
    // and nothing else.
    foreach (range(1, 3) as $ignored) {
        $this->post(route('password.email'), ['email' => 'ada@example.test']);
    }

    $refused = $this->post(route('password.email'), ['email' => 'ada@example.test']);

    // Paired with an operator who has spent nothing, through the same endpoint
    // in the same run. The refusal alone would pass just as well against a
    // limiter that refuses everyone, or one keyed on nothing — this second half
    // is what says the budget belongs to the person named in the request.
    $accepted = $this->post(route('password.email'), ['email' => 'bo@example.test']);

    expect($refused->getStatusCode())->toBe(429)
        ->and($accepted->getStatusCode())->not->toBe(429);

    Notification::assertSentTo($bo, ResetPassword::class);
});

test('submitting a reset does not spend the budget for requesting one', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.test']);

    // Requesting a link and submitting a reset are deliberately separate
    // budgets, and this is the sequence where sharing one would bite: three
    // links requested because the first two seemed not to arrive, then a
    // submission that fails because the older link is stale, then one that
    // fails because the password was mistyped, and only then the attempt that
    // should work. Sharing a counter puts that last attempt sixth against a
    // ceiling of five, so the operator is refused for having followed the
    // instructions carefully.
    //
    // The counts are load-bearing. An earlier version of this test spent only
    // the three requests before submitting once, and could not fail: the
    // request ceiling is below the submission ceiling, so a shared counter
    // never overflowed from requests alone. Measured by merging the two keys
    // and watching it stay green.
    foreach (range(1, 3) as $ignored) {
        $this->post(route('password.email'), ['email' => 'ada@example.test']);
    }

    foreach (range(1, 2) as $ignored) {
        $this->post(route('password.update'), [
            'token' => 'a-stale-or-mistyped-attempt',
            'email' => 'ada@example.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);
    }

    // Read the token from the recorded notification rather than through
    // assertSentTo's callback: that callback runs once per sent notification,
    // and three were sent above, so submitting from inside it would post three
    // times and quietly spend a budget this test is trying to measure.
    $token = collect(Notification::sent($user, ResetPassword::class))->last()->token;

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'ada@example.test',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    expect($response->getStatusCode())->not->toBe(429);

    $response->assertSessionHasNoErrors();

    // The paired positive claim, made in this same run (L-19). Everything above
    // asserts that something was *not* refused, which is satisfied perfectly by
    // an application that throttles nothing at all — measured, by unregistering
    // the limiter and watching an earlier version of this test stay green while
    // its three siblings went red. Showing that the request budget really is
    // spent is what makes the submission surviving it mean anything.
    $furtherRequest = $this->post(route('password.email'), ['email' => 'ada@example.test']);

    expect($furtherRequest->getStatusCode())->toBe(429);
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
