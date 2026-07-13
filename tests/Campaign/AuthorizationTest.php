<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

test('every permission is registered as a gate', function (): void {
    // The configuration invariant behind every allow/deny test below. The
    // behavioural halves can only ever exercise the permissions someone
    // remembered to write a test for; this one covers the enum itself, so a
    // case added later cannot slip through unregistered.
    //
    // It matters because forgetting to register a permission raises nothing.
    // Laravel denies an ability it has never heard of, so an unregistered
    // permission denies everyone -- indistinguishable, from the outside, from
    // a guard that is working correctly and saying no.
    expect(Permission::cases())->not->toBeEmpty();

    foreach (Permission::cases() as $permission) {
        expect(Gate::has($permission->value))
            ->toBeTrue("Permission::{$permission->name} has no gate registered for it.");
    }
});

test('an owner may manage operators', function (): void {
    $owner = User::factory()->owner()->create();

    expect($owner->can(Permission::ManageOperators->value))->toBeTrue()
        ->and(Gate::forUser($owner)->allows(Permission::ManageOperators->value))->toBeTrue();
});

test('a staff operator may not manage operators', function (): void {
    // Paired deliberately with the test above rather than standing alone. On
    // its own this assertion cannot fail for the right reason: delete the gate
    // registration entirely and the ability becomes unknown, which Laravel also
    // denies, so it would stay green against an authorization spine that does
    // not exist. What makes it evidence is that the *same* ability, checked the
    // *same* way, is allowed for an owner.
    $staff = User::factory()->create();

    expect($staff->can(Permission::ManageOperators->value))->toBeFalse()
        ->and(Gate::forUser($staff)->denies(Permission::ManageOperators->value))->toBeTrue();
});

test('the roles disagree about the same permission checked the same way', function (): void {
    // The pairing above, stated as one assertion so that a future edit cannot
    // quietly drop half of it and leave a deny test guarding nothing.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows(Permission::ManageOperators->value))
        ->not->toBe(Gate::forUser($staff)->allows(Permission::ManageOperators->value));
});

test('the can middleware refuses a staff operator on a campaign route', function (): void {
    // Proves the spine works where a module will actually use it -- on a route,
    // through the framework's own middleware -- rather than only through a
    // direct Gate call. The route is defined here because Phase 0 has nothing
    // to govern yet; shipping a placeholder route in routes/tenant.php would be
    // product surface invented to satisfy a test.
    Route::middleware(['tenant', 'auth', 'can:'.Permission::ManageOperators->value])
        ->get('authorization-probe', fn () => response('reached'))
        ->name('authorization-probe');

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/authorization-probe'))
        ->assertForbidden();
});

test('the can middleware admits an owner on a campaign route', function (): void {
    Route::middleware(['tenant', 'auth', 'can:'.Permission::ManageOperators->value])
        ->get('authorization-probe', fn () => response('reached'))
        ->name('authorization-probe');

    $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/authorization-probe'))
        ->assertOk()
        ->assertSee('reached');
});
