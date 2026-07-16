<?php

use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    // Spends the budget by actually failing to sign in, rather than seeding a
    // counter under a hand-computed key. The scaffold's version restated the
    // framework's key formula — md5('login'.email.'|'.ip) — back at it, so it
    // reported "rate limiting works" only while this application keyed its
    // limiter exactly the way Fortify shipped it, and went red the moment the
    // key legitimately changed. What is worth asserting is that six wrong
    // passwords are refused, whatever the key happens to be.
    foreach (range(1, 5) as $ignored) {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    // Deliberately not assertTooManyRequests(). When this assertion fails the
    // response is a redirect carrying validation errors, and Laravel's failure
    // formatter reads that session bag in a way that fatals on it — so the
    // idiomatic assertion reports "Call to a member function all() on array"
    // instead of the status it actually got. Measured by raising the limit and
    // watching it break. Comparing the code directly keeps the red legible.
    expect($response->getStatusCode())->toBe(429);
});
