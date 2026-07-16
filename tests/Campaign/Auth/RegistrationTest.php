<?php

use App\Authorization\OperatorRole;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the first operator to register claims the campaign as its owner', function () {
    // A campaign has to get its first Owner from somewhere, and provisioning
    // does not supply one -- campaign:create makes a database and a domain, not
    // an identity. So the first registration claims it.
    expect(User::query()->count())->toBe(0);

    $this->post(route('register.store'), [
        'name' => 'First Arrival',
        'email' => 'first@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::query()->where('email', 'first@example.test')->sole()->role)
        ->toBe(OperatorRole::Owner);
});

test('every operator registering after the first joins as staff', function () {
    // The half that matters for security. Registration is open on every
    // campaign, so without this anyone who found the address would arrive with
    // the same authority as the person who set the campaign up.
    User::factory()->owner()->create(['email' => 'incumbent@example.test']);

    $this->post(route('register.store'), [
        'name' => 'Second Arrival',
        'email' => 'second@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $joiner = User::query()->where('email', 'second@example.test')->sole();

    expect($joiner->role)->toBe(OperatorRole::Staff)
        ->and($joiner->can('manage-operators'))->toBeFalse();

    // And the incumbent is untouched -- a second registration must not demote
    // or displace the operator who claimed the campaign.
    expect(User::query()->where('email', 'incumbent@example.test')->sole()->role)
        ->toBe(OperatorRole::Owner);
});

test('registering is metered', function () {
    // Fortify offers no limiter hook for registration, so this endpoint took
    // unauthenticated POSTs without any budget at all until a limiter was
    // listed on Fortify's middleware key.
    //
    // Every attempt below omits the password confirmation and is rejected, so
    // no operator is created and no session is authenticated -- the throttle
    // sits ahead of the controller and counts the attempt either way. Spending
    // the budget with *valid* registrations would sign the first one in and
    // send the rest through the guest redirect instead, measuring nothing.
    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), [
            'name' => 'Flood '.$attempt,
            'email' => 'flood'.$attempt.'@example.test',
            'password' => 'password',
        ]);
    }

    $response = $this->post(route('register.store'), [
        'name' => 'Flood 6',
        'email' => 'flood6@example.test',
        'password' => 'password',
    ]);

    expect($response->getStatusCode())->toBe(429)
        ->and(User::query()->count())->toBe(0);
});

test('a registration cannot claim ownership by posting a role', function () {
    // The escalation path this whole design is arranged to close, exercised
    // through the real endpoint rather than the model: the request names a
    // role, and the campaign already has an owner, so the joiner must be Staff.
    User::factory()->owner()->create(['email' => 'incumbent@example.test']);

    $this->post(route('register.store'), [
        'name' => 'Mallory Vance',
        'email' => 'escalate@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => OperatorRole::Owner->value,
    ]);

    expect(User::query()->where('email', 'escalate@example.test')->sole()->role)
        ->toBe(OperatorRole::Staff);
});
