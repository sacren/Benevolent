<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * Who may write a blast and who may send one, asked at both levels this
 * application answers it: the permissions a role holds, and the policy that
 * routes a model's abilities onto them. Kept in one file for the reason
 * SupporterAuthorizationTest gives -- a reader debugging a refusal wants both,
 * and the second half is evidence only in the light of the first.
 *
 * Separate from AuthorizationTest, which is the spine's own file, and it needs
 * no edit there: the sweep proving every permission is registered iterates
 * Permission::cases(), so the three blast cases are covered by it for free and
 * a case left unregistered goes red there rather than being missed here.
 *
 * **This is the first module where the split carries real weight, and the
 * honest ratio is worse than the flattering one.** Of the six supporter
 * abilities exactly two discriminate, both withheld on leverage rather than on
 * reachability. Sending is the first ability withheld because the act itself
 * cannot be taken back. Counting what that produces rather than what it feels
 * like: three of ten policy abilities discriminate, and four of eight
 * permissions. The split got better and the ratio got worse, which is the
 * honest way round and the same way round Phase 1 recorded at its own Step 5.
 *
 * There is deliberately **no `can:` middleware probe here**, unlike the
 * supporter file. Every shipped campaign route settles authority with
 * `$this->authorize(...)` inside its controller -- routes/tenant.php says so in
 * two comments -- so a middleware probe would prove a mechanism this
 * application does not use. Step 3's controller tests are where the policy gets
 * driven the way the module will actually drive it.
 */

test('either role may see and write a blast', function (): void {
    // Refuses nobody today, which is the point of stating it. Knowing what the
    // campaign has already said to its list is the campaign's work, and a draft
    // that is never sent has reached nobody -- so both grants are safe in a way
    // the third is not.
    //
    // It is still a guard rather than decoration: unlike a deny, an allow
    // cannot pass against an authorization layer that is missing. Drop either
    // grant from Staff's list and these go red, and nothing else in the suite
    // would notice -- the sweep checks that a permission is *registered*, never
    // that a role was actually given it.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows(Permission::ViewBlasts->value))->toBeTrue()
        ->and(Gate::forUser($owner)->allows(Permission::EditBlasts->value))->toBeTrue()
        ->and(Gate::forUser($staff)->allows(Permission::ViewBlasts->value))->toBeTrue()
        ->and(Gate::forUser($staff)->allows(Permission::EditBlasts->value))->toBeTrue();
});

test('the roles disagree about sending a blast, and both directions are pinned', function (): void {
    // The L-16 pairing, in the refined shape Phase 1 arrived at for the export
    // ability rather than the three-test shape it used for removal. Both
    // directions are asserted inside one test, so neither half can be edited
    // away and leave the other guarding nothing: delete either and what remains
    // is a test named for a disagreement it no longer checks. The separate
    // `->not->toBe()` third test that removal needs is redundant here, because
    // that form is satisfied by *either* direction and these lines pin both.
    //
    // Why the pairing is mandatory rather than tidy: a gate denies an ability
    // it has never heard of exactly as it denies one a role was refused, so the
    // deny lines below are evidence only because the identical check, made the
    // identical way, answers differently for an owner. Dropping the whole
    // authorization spine at Phase 0 Step 8 left 2 of 6 tests green and they
    // were precisely the deny tests.
    //
    // Both call paths are exercised, because the policy answers through
    // `$operator->can()` while the front end and the middleware reach the gate
    // through the facade, and a registration that satisfied one and not the
    // other would be caught by neither alone.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect($owner->can(Permission::SendBlasts->value))->toBeTrue()
        ->and(Gate::forUser($owner)->allows(Permission::SendBlasts->value))->toBeTrue()
        ->and($staff->can(Permission::SendBlasts->value))->toBeFalse()
        ->and(Gate::forUser($staff)->denies(Permission::SendBlasts->value))->toBeTrue();
});
