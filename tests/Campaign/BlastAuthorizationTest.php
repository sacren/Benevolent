<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Blasts\BlastPolicy;
use App\Models\Blast;
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

// -----------------------------------------------------------------------------
// The policy: the same authority, reached the way a controller will reach it.
// -----------------------------------------------------------------------------

test('a blast is governed by a policy at all', function (): void {
    // The wiring guard, and the one that fails when #[UsePolicy] is deleted
    // from the model. Nothing else in this file would notice: without the
    // attribute the gate finds no policy, every ability against a Blast is
    // unknown, and an unknown ability is refused exactly as a refused one -- so
    // the send deny below stays green against a model governed by nothing.
    //
    // These three abilities are checked for a Staff operator specifically,
    // because they are the ones both roles hold: if the policy is unreachable
    // they go false, and no role change can explain it away.
    $staff = User::factory()->create();
    $blast = Blast::factory()->create();

    expect(Gate::getPolicyFor(Blast::class))->toBeInstanceOf(BlastPolicy::class)
        ->and(Gate::forUser($staff)->allows('viewAny', Blast::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('create', Blast::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $blast))->toBeTrue();
});

test('the roles disagree about sending, through the policy, and both directions are pinned', function (): void {
    // Distinct from the permission-level pairing above in what it can catch:
    // that one says an Owner holds SendBlasts and a Staff operator does not,
    // this one says the policy routes the `send` ability onto that permission
    // and not onto another. A policy answering `send` from EditBlasts -- the
    // tempting reuse, since composing and sending are the same page's two
    // buttons -- passes every permission-level assertion in this file and fails
    // this test's second line.
    //
    // Both directions in one test, for the reason the permission-level pairing
    // gives: an allow that can be edited away leaves a deny guarding nothing,
    // and a deny alone is satisfied by a policy that is simply unreachable.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $blast = Blast::factory()->create();

    expect(Gate::forUser($owner)->allows('send', $blast))->toBeTrue()
        ->and(Gate::forUser($staff)->denies('send', $blast))->toBeTrue();
});

test('a draft already committed to sending is still the policy\'s to allow', function (): void {
    // The state-versus-authority decision, pinned so that folding
    // BlastStatus::isCommitted() into the policy becomes a visible change
    // rather than a quiet one.
    //
    // This asserts something that looks wrong on first reading, so the reason
    // is stated rather than left to be re-derived: an Owner is allowed to
    // `send` a blast that has already been sent. That is correct here because
    // the policy answers *who may act*, and the Owner may. What stops the
    // second send is the check constraint on `blasts` and the lock the sending
    // path will carry -- mechanisms, not courtesies -- and Step 4 owns them.
    //
    // Folding the state check in would make send() return false for two
    // different causes, which is the silent-denial hazard this module already
    // carries, one level deeper: an Owner would be told "you may not send this"
    // when the truth is "this was already sent". If a later step decides
    // otherwise, this test is the thing that must be deliberately rewritten,
    // which is exactly the visibility it exists to provide.
    $owner = User::factory()->owner()->create();
    $sent = Blast::factory()->sent()->create();

    expect($sent->status->isCommitted())->toBeTrue()
        ->and(Gate::forUser($owner)->allows('send', $sent))->toBeTrue();
});
