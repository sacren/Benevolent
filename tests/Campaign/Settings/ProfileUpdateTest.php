<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('a changed address is stored the way sign-in will look for it', function () {
    // D-61. Fortify lowercases whatever an operator types at sign-in and then
    // compares it exactly, so an address stored with a capital in it can never
    // be signed in with. The operator beside them is the wrong answer this has
    // to be able to see: stored in lower case from the start, untouched.
    $bystander = User::factory()->create(['email' => 'bystander@example.test']);
    $user = User::factory()->create(['email' => 'before@example.test']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Jean Sacren',
            'email' => 'Jean.Sacren@Example.test',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('jean.sacren@example.test')
        ->and($bystander->refresh()->email)->toBe('bystander@example.test');

    // And the reason it matters, through the real sign-in rather than asserted
    // about the column: the operator can come back in, typing it either way.
    auth()->logout();

    $this->post(route('login.store'), [
        'email' => 'Jean.Sacren@Example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('resubmitting the same address in another casing is not a change of address', function () {
    // Folded before validation, so this is the operator's own address rather
    // than a new one -- which is what keeps its verification in place.
    $user = User::factory()->create(['email' => 'same@example.test']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Same Person',
            'email' => 'Same@Example.test',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('same@example.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('an address another operator holds cannot be taken by changing its casing', function () {
    User::factory()->create(['email' => 'taken@example.test']);
    $user = User::factory()->create(['email' => 'mine@example.test']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Mine',
            'email' => 'Taken@Example.test',
        ])
        ->assertSessionHasErrors('email');

    expect($user->refresh()->email)->toBe('mine@example.test');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
