<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * Who may do what to a campaign's supporters, asked at the permission level.
 *
 * Separate from AuthorizationTest, which is the spine's own file -- the sweep
 * proving every permission is registered, and the roster permission the spine
 * was built around. This file answers a module's question instead, and it needs
 * no edit to that one: the sweep iterates Permission::cases(), so the three
 * cases added here are covered by it for free, and a case left unregistered
 * goes red there rather than being missed here.
 *
 * Recorded because the step this file arrived with was framed as the first real
 * test of whether the Owner/Staff split is the right one, and it is a thinner
 * test than that sounds: of the three supporter permissions, both roles hold
 * two. Only removal separates them. The abilities a campaign director would
 * actually withhold from a junior organiser are removal and *export*, and
 * export does not exist yet -- so the split's second real test is Step 5's,
 * not this one's.
 */

test('either role may see and edit the campaign supporters', function (): void {
    // This refuses nobody today, which is the point of stating it: seeing the
    // list and keeping it current is the campaign's work rather than authority
    // over it, so a role that could not do this could do nothing at all.
    //
    // It is still a guard rather than decoration -- unlike a deny, an allow
    // cannot pass against an authorization layer that is missing. Drop either
    // grant from Staff's list and these go red, and nothing else in the suite
    // would notice: the sweep checks that a permission is *registered*, never
    // that a role was actually given it.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows(Permission::ViewSupporters->value))->toBeTrue()
        ->and(Gate::forUser($owner)->allows(Permission::EditSupporters->value))->toBeTrue()
        ->and(Gate::forUser($staff)->allows(Permission::ViewSupporters->value))->toBeTrue()
        ->and(Gate::forUser($staff)->allows(Permission::EditSupporters->value))->toBeTrue();
});

test('an owner may remove a supporter', function (): void {
    // The allow half of the pair below. A deny test cannot fail on its own --
    // an ability that was never registered is refused exactly as one a role was
    // refused -- so the denial in the next test is evidence only because this
    // one says the identical check answers differently for an owner.
    $owner = User::factory()->owner()->create();

    expect($owner->can(Permission::DeleteSupporters->value))->toBeTrue()
        ->and(Gate::forUser($owner)->allows(Permission::DeleteSupporters->value))->toBeTrue();
});

test('a staff operator may not remove a supporter', function (): void {
    // Removal is the one supporter ability the roles disagree about. A
    // supporter row has no soft delete and nothing to restore it from, and the
    // ordinary way to stop contacting someone is to unsubscribe them, which is
    // what keeps a later import from putting them back.
    $staff = User::factory()->create();

    expect($staff->can(Permission::DeleteSupporters->value))->toBeFalse()
        ->and(Gate::forUser($staff)->denies(Permission::DeleteSupporters->value))->toBeTrue();
});

test('the roles disagree about removing a supporter, checked the same way', function (): void {
    // The pairing above stated as a single assertion, so that a later edit
    // cannot quietly delete the allow half and leave a deny test guarding
    // nothing. The two tests above can each be edited alone; this one cannot be
    // half-dropped without deleting the whole thing.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows(Permission::DeleteSupporters->value))
        ->not->toBe(Gate::forUser($staff)->allows(Permission::DeleteSupporters->value));
});
