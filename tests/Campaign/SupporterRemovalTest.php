<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\User;

/*
 * Removing a supporter, and telling the browser who may.
 *
 * These belong together rather than in the write-path file, because removal is
 * the only ability in this module the two roles disagree about and therefore
 * the only control a page has to hide. The authority and the control it governs
 * are one change, and splitting them would have shipped a button that fails for
 * half the operators who can see it.
 *
 * **What is guarded here, and what is not.** These tests prove the server
 * refuses the request and that the page is handed the right permissions. They
 * do **not** prove the Remove control is absent from the DOM for a Staff
 * operator: nothing in this suite renders a Vue component, so a page that
 * ignored `auth.permissions` entirely and drew the button for everybody would
 * pass every assertion below. That is a courtesy failing rather than a security
 * one -- the policy still refuses the click -- but it is unguarded, and it is
 * one of the things the frontend verification hole leaves open.
 */

test('an owner removes a supporter', function (): void {
    $supporter = Supporter::factory()->create();

    $this->actingAs(User::factory()->owner()->create())
        ->delete($this->campaignUrl('/supporters/'.$supporter->getKey()))
        ->assertRedirect(route('supporters.index'));

    expect(Supporter::query()->whereKey($supporter->getKey())->exists())->toBeFalse();
});

test('a staff operator may not remove a supporter', function (): void {
    // The deny half of the pair above, and it is evidence only because of it: a
    // route that did not exist, or one that refused everybody, satisfies this
    // assertion exactly as a working guard does.
    $supporter = Supporter::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl('/supporters/'.$supporter->getKey()))
        ->assertForbidden();

    expect(Supporter::query()->whereKey($supporter->getKey())->exists())->toBeTrue();
});

test('the roles disagree about removal through the route, checked the same way', function (): void {
    // Stated as one assertion so a later edit cannot drop the allow half and
    // leave a deny test guarding nothing -- the same pairing discipline the
    // policy tests use, applied where the request actually arrives.
    $forOwner = Supporter::factory()->create(['email' => 'owner-removes@example.test']);
    $forStaff = Supporter::factory()->create(['email' => 'staff-cannot@example.test']);

    $ownerRemoved = $this->actingAs(User::factory()->owner()->create())
        ->delete($this->campaignUrl('/supporters/'.$forOwner->getKey()))
        ->isRedirect();

    $staffRemoved = $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl('/supporters/'.$forStaff->getKey()))
        ->isRedirect();

    expect($ownerRemoved)->not->toBe($staffRemoved);
});

// -----------------------------------------------------------------------------
// Deferral 8: what the browser is told about authority.
// -----------------------------------------------------------------------------

test('the page is told what an operator may do, and never what they are', function (): void {
    // Both halves in one run, because either alone is satisfied by the wrong
    // thing. The absence of `role` passes against a page that shares no auth
    // props at all; the presence of permissions passes against a page that
    // also still leaks the role beside them.
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get($this->campaignUrl('/supporters'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', $owner->email)
            // Withheld from the serialized operator, so a component cannot
            // branch on it even carelessly.
            ->missing('auth.user.role')
            ->has('auth.permissions')
        );
});

test('the permissions shared with the page are the ones the role actually holds', function (): void {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $permissionsFor = function (User $operator): array {
        $response = $this->actingAs($operator)->get($this->campaignUrl('/supporters'))->assertOk();

        return $response->viewData('page')['props']['auth']['permissions'];
    };

    $ownerHolds = $permissionsFor($owner);
    $staffHolds = $permissionsFor($staff);

    // The positive and the negative on the same permission, resolved the same
    // way -- the L-16 pairing applied to a prop rather than to a gate. Removal
    // is the one they disagree about, so it is the one the page can act on.
    expect($ownerHolds)->toContain(Permission::DeleteSupporters->value)
        ->and($staffHolds)->not->toContain(Permission::DeleteSupporters->value);

    // And the abilities both hold really are shared for both, so a page hiding
    // Edit from Staff would be wrong rather than cautious.
    expect($ownerHolds)->toContain(Permission::ViewSupporters->value)
        ->and($staffHolds)->toContain(Permission::ViewSupporters->value)
        ->and($ownerHolds)->toContain(Permission::EditSupporters->value)
        ->and($staffHolds)->toContain(Permission::EditSupporters->value);

    // The configuration invariant behind the behaviour: the list is resolved by
    // iterating the enum, so a permission added later is shared without anyone
    // editing this middleware -- exactly as it is registered as a gate without
    // anyone editing the provider. A hand-written list would pass every
    // assertion above and quietly omit the fourth case.
    expect($ownerHolds)->toHaveCount(count(Permission::cases()));
});
