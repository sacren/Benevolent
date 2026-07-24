<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SupporterPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
 * Who may do what to a campaign's supporters, asked at both levels this
 * application answers it: the permissions a role holds, and the policy that
 * routes a model's abilities onto them. Kept in one file because a reader
 * debugging a refusal wants to see both, and because the second half is
 * evidence only in the light of the first.
 *
 * Separate from AuthorizationTest, which is the spine's own file -- the sweep
 * proving every permission is registered, and the roster permission the spine
 * was built around. This file answers a module's question instead, and it needs
 * no edit to that one: the sweep iterates Permission::cases(), so every
 * supporter case is covered by it for free -- the three this file arrived with
 * and ExportSupporters after them -- and a case left unregistered goes red
 * there rather than being missed here.
 *
 * **The split's second real test arrived, and it went the way the paragraph
 * this replaces predicted.** When this file was written there were three
 * supporter permissions, both roles held two, and only removal separated them;
 * the note here said the other ability a campaign director would actually
 * withhold is *export*, which did not exist yet. Step 5 built it and reached
 * the same answer independently -- ExportSupporters is Owner-only -- so the
 * module now has six abilities and two that discriminate, rather than five and
 * one. The split is exercised by this module twice; it does not rest on
 * operator management alone.
 *
 * The two halves were measured against each other rather than assumed to be
 * complementary, by deleting the four permission-level tests that existed then
 * (the export pair below arrived later) and rebuilding the role defects they
 * exist for. **Both defects are still caught without them** -- an
 * escalation reddens three policy tests, a withdrawn grant reddens one -- so
 * they are not the last line of defence for anything, and a docblock claiming
 * they were would be believed without being checked. What they do carry is
 * legibility and independence. Without them, a Staff operator quietly losing
 * ViewSupporters surfaces as `a supporter is governed by a policy at all`,
 * which names the wrong cause: the policy is wired, the grant is what moved.
 * And they are the only supporter tests that survive the policy being deleted
 * or bypassed, which matters because the permissions are the security posture
 * while the policy is one consumer of it.
 *
 * Two further properties of the policy are asserted in
 * tests/Unit/SupporterPolicyWiringTest.php rather than here, because neither
 * changes any answer this file checks: that the policy reads permissions and
 * never the role, and that nothing occupies the path the gate would guess.
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

// -----------------------------------------------------------------------------
// The policy: the same authority, reached the way a controller will reach it.
// -----------------------------------------------------------------------------

test('a supporter is governed by a policy at all', function (): void {
    // The wiring guard, and the one that fails when #[UsePolicy] is deleted
    // from the model. Nothing else in this file would notice: without the
    // attribute the gate finds no policy, every ability against a Supporter is
    // unknown, and an unknown ability is refused exactly as a refused one --
    // so the deny tests below stay green against a model governed by nothing.
    //
    // These three abilities are checked for a Staff operator specifically,
    // because they are the ones both roles hold: if the policy is unreachable
    // they go false, and no role change can explain it away.
    $staff = User::factory()->create();
    $supporter = Supporter::factory()->create();

    expect(Gate::getPolicyFor(Supporter::class))->toBeInstanceOf(SupporterPolicy::class)
        ->and(Gate::forUser($staff)->allows('viewAny', Supporter::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('create', Supporter::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $supporter))->toBeTrue();
});

test('either role may import a list, through the policy', function (): void {
    // `import` refuses nobody today, exactly as `viewAny` and `create` refuse
    // nobody, and it is stated for the same reason: an allow cannot pass
    // against an authorization layer that is missing, so it guards where a deny
    // could not. There is deliberately no deny half here, because there is no
    // role to write one from -- both roles hold EditSupporters, and inventing a
    // third to make the pairing look symmetrical would be fabricating a split
    // the product has not asked for.
    //
    // What it does say beyond the permission-level test above is that the
    // policy routes `import` onto EditSupporters rather than onto some other
    // permission. A policy answering it from DeleteSupporters would pass every
    // permission-level assertion in this file and fail this one for Staff.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows('import', Supporter::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('import', Supporter::class))->toBeTrue();
});

test('the roles disagree about exporting the list, and both directions are pinned', function (): void {
    // **One test where removal has three, and that is a deliberate improvement
    // rather than a corner cut.** The trio above states an allow, a deny, and
    // then a third assertion that the two differ -- and the third is needed
    // there precisely because `->not->toBe()` is satisfied by *either*
    // direction, so on its own it would pass against a policy that let Staff
    // export and refused the Owner. Asserting both directions inside one test
    // pins each of them and cannot be half-dropped, because there is no other
    // half to leave behind: delete either line and what remains is a test named
    // for a disagreement that no longer checks one.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows(Permission::ExportSupporters->value))->toBeTrue()
        ->and(Gate::forUser($staff)->denies(Permission::ExportSupporters->value))->toBeTrue();
});

test('an owner may export the list, through the policy', function (): void {
    // Distinct from the permission-level test above in the way the delete pair
    // is: that one says an Owner holds ExportSupporters, this one says the
    // policy routes the `export` ability onto that permission and not onto
    // another. A policy answering `export` from ViewSupporters -- the tempting
    // reuse, since Staff already read every row on the page -- passes the test
    // above and fails this one's second line.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows('export', Supporter::class))->toBeTrue()
        ->and(Gate::forUser($staff)->denies('export', Supporter::class))->toBeTrue();
});

test('an owner may delete a supporter through the policy', function (): void {
    // Distinct from the permission-level allow above it: that one says an Owner
    // holds DeleteSupporters, this one says the policy routes the `delete`
    // ability onto that permission rather than onto some other one. A policy
    // answering `delete` from EditSupporters would pass the first and fail this.
    $owner = User::factory()->owner()->create();
    $supporter = Supporter::factory()->create();

    expect(Gate::forUser($owner)->allows('delete', $supporter))->toBeTrue();
});

test('a staff operator may not delete a supporter through the policy', function (): void {
    $staff = User::factory()->create();
    $supporter = Supporter::factory()->create();

    expect(Gate::forUser($staff)->allows('delete', $supporter))->toBeFalse()
        ->and(Gate::forUser($staff)->denies('delete', $supporter))->toBeTrue();
});

test('the roles disagree about the delete ability, checked the same way', function (): void {
    // Stated as one assertion for the same reason as its permission-level
    // twin: the allow and the deny above can each be edited away alone, and a
    // surviving deny test would guard nothing.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $supporter = Supporter::factory()->create();

    expect(Gate::forUser($owner)->allows('delete', $supporter))
        ->not->toBe(Gate::forUser($staff)->allows('delete', $supporter));
});

test('the can middleware refuses a staff operator deleting a supporter', function (): void {
    // Proves the policy answers where a module will actually consult it -- on a
    // route, through the framework's own middleware, with the supporter
    // resolved by route-model binding out of the campaign's own database.
    //
    // The route is defined here rather than shipped in routes/tenant.php
    // because this step builds no supporter surface; that is Step 3's, and a
    // placeholder route would be product invented to satisfy a test. The
    // precedent is AuthorizationTest, which does the same for the roster.
    Route::middleware(['tenant', 'auth', 'can:delete,supporter'])
        ->get('supporter-authorization-probe/{supporter}', fn (Supporter $supporter) => response('reached'));

    $supporter = Supporter::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporter-authorization-probe/'.$supporter->getKey()))
        ->assertForbidden();
});

test('the can middleware admits an owner deleting a supporter', function (): void {
    Route::middleware(['tenant', 'auth', 'can:delete,supporter'])
        ->get('supporter-authorization-probe/{supporter}', fn (Supporter $supporter) => response('reached'));

    $supporter = Supporter::factory()->create();

    $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/supporter-authorization-probe/'.$supporter->getKey()))
        ->assertOk()
        ->assertSee('reached');
});
