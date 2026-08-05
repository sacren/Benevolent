<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Segment;
use App\Models\User;
use App\Segments\SegmentPolicy;
use Illuminate\Support\Facades\Gate;

/*
 * Who may name a campaign's segments, and who may use one.
 *
 * **This file has no permission-level half, unlike the supporter and blast
 * files, and that is D-25 rather than an omission.** Segments ride on the
 * supporter permissions: viewAny answers from ViewSupporters, create, update
 * and delete from EditSupporters, and no Permission case was added. So the
 * permission-level assertions this file would otherwise open with already exist
 * in tests/Campaign/SupporterAuthorizationTest.php -- "either role may see and
 * edit the campaign supporters" pins both grants in both directions -- and
 * restating them here would be a second copy of one fact with no failure mode
 * of its own. It also needs no edit to tests/Campaign/AuthorizationTest.php:
 * the sweep proving every permission is registered iterates Permission::cases(),
 * and this module added none for it to miss.
 *
 * **Why segments ride rather than take their own cases.** A segment is a new
 * surface over an authority this product already has. The only thing one can do
 * today is narrow the supporter list, which is exactly ViewSupporters' and
 * EditSupporters' subject; and every ground this application has used to
 * withhold an ability -- governing the roster, taking a copy off the platform,
 * leverage over an unrecoverable row, an act that cannot be recalled -- is
 * measurably absent here. The argument that looked like it might produce a new
 * authority is that a segment is shared and mutable, so editing one changes
 * what another operator's draft blast would do; measured against the repo, that
 * reaches no outcome Staff cannot already reach, because EditBlasts is
 * deliberately theirs and re-aiming any draft's postcode_prefixes is already
 * unconditionally Staff's. The trigger for segments to take their own cases is
 * D-26 giving a blast a pointer at a segment, at which point the authority
 * stops being "keeping supporter details current".
 *
 * **The consequence for this file, stated rather than dressed up: no segment
 * ability discriminates between the two roles, so there is not one deny test
 * here.** Counting what that produces -- three of fourteen policy abilities
 * discriminate, and four of eight permissions, the second unchanged because
 * this module added none. Phase 1 ended with a better split and a worse ratio
 * and Phase 2 did the same; this module makes the ratio worse and the split not
 * at all, which is the honest outcome. Inventing an Owner-only segment ability
 * so these tests looked interesting would be fabricating security posture to
 * satisfy a guard.
 *
 * **What keeps an all-allow file from being decoration, and it is not the same
 * mechanism the other two use.** L-16's pairing carries the evidence there: a
 * deny is worthless alone because a gate refuses an unheard-of ability exactly
 * as it refuses a withheld one, so the deny is evidence only beside an allow.
 * Here there is no deny to rescue -- and an allow, unlike a deny, cannot pass
 * against an authorization layer that is missing, so every assertion below
 * fails if the wiring goes: deleting #[UsePolicy] from the model reddens all
 * **5 of 413**, with no assertion of any kind left standing over a model
 * governed by nothing.
 *
 * What the allows additionally catch is the mapping, and they catch it because
 * they are asserted for **Staff**: Staff holds exactly the four permissions
 * that discriminate nobody, so any ability mis-mapped onto a withheld
 * permission turns a Staff allow red while every Owner assertion stays green.
 * Measured on both ends of the policy rather than argued -- delete() answered
 * from DeleteSupporters reddens **2 of 413**, and viewAny() answered from
 * ExportSupporters reddens **2**, in each case the wiring test and the pair
 * naming that ability, and in each case with the Owner assertions untouched.
 *
 * **One defect here is caught by nothing and the file says so rather than
 * implying otherwise:** a method rewritten to `return true` leaves 413 of 413
 * green, because no ability discriminates, and neither PHPStan nor Pint objects
 * to the import left unused behind it. What closes it is the same condition
 * that gives segments their own permissions -- the first segment ability that
 * discriminates makes the rewrite visible to an ordinary allow/deny pair.
 * tests/Unit/SegmentPolicyWiringTest.php carries that measurement and the guard
 * that was drafted for it and rejected.
 *
 * Two further properties of the policy are asserted in
 * tests/Unit/SegmentPolicyWiringTest.php rather than here, because neither
 * changes any answer this file checks: that the policy reads permissions and
 * never the role, and that nothing occupies the path the gate would guess.
 */

test('a segment is governed by a policy at all', function (): void {
    // The wiring guard, and the one that fails when #[UsePolicy] is deleted
    // from the model. Without the attribute the gate finds no policy at all --
    // measured on this model, Gate::getPolicyFor(Segment::class) returns null
    // -- so every ability against a Segment becomes unknown and is refused.
    //
    // Checked for a Staff operator specifically, as the blast file does: these
    // are abilities both roles hold, so if the policy is unreachable they go
    // false and no role change can explain it away.
    $staff = User::factory()->create();
    $segment = Segment::factory()->create();

    expect(Gate::getPolicyFor(Segment::class))->toBeInstanceOf(SegmentPolicy::class)
        ->and(Gate::forUser($staff)->allows('viewAny', Segment::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('create', Segment::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $segment))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('delete', $segment))->toBeTrue();
});

test('either role may read the campaign segments, through the policy', function (): void {
    // Refuses nobody today, which is the point of stating it: a segment is a
    // saved narrowing of the supporter list, so an operator who may read the
    // list may read the ways it has been narrowed, and D-24 keeps every string
    // a campaign typed about a person out of the rule -- so this discloses
    // nothing the list page does not.
    //
    // Both call paths are exercised, for the reason the blast file gives: the
    // policy answers through $operator->can() while the front end and the
    // middleware reach the gate through the facade, and a registration
    // satisfying one and not the other would be caught by neither alone.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($owner)->allows('viewAny', Segment::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('viewAny', Segment::class))->toBeTrue()
        ->and($staff->can(Permission::ViewSupporters->value))->toBeTrue();
});

test('either role may name and re-aim a segment, through the policy', function (): void {
    // Naming a narrowing and correcting the postcodes it points at is the
    // campaign's work rather than authority over it, which is EditSupporters'
    // own reason for being shared. Nothing leaves the platform when a segment
    // is saved and no supporter row is touched.
    //
    // The Staff halves are what catch a mis-mapping: create() or update()
    // answered from ExportSupporters, DeleteSupporters or SendBlasts would
    // leave the two Owner assertions green and redden these.
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $segment = Segment::factory()->create();

    expect(Gate::forUser($owner)->allows('create', Segment::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('create', Segment::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $segment))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $segment))->toBeTrue();
});

test('a staff operator may delete a segment, and that is not DeleteSupporters', function (): void {
    // The assertion in this file most likely to be read as a mistake, so the
    // reason is stated rather than left to be re-derived. Removing a supporter
    // is Owner-only because the row has no soft delete and nothing to recover
    // it from; a segment holds a name and a handful of postcode prefixes, which
    // retype in seconds, and destroys no supporter and no blast when it goes.
    // Answering delete() from DeleteSupporters would also make removing a
    // segment harder than emptying one, since an operator holding
    // EditSupporters can already strip its prefixes down to a rule matching
    // nobody.
    //
    // The second line is what makes the first evidence rather than an
    // assertion: this same operator is refused DeleteSupporters through the
    // same gate, so a policy that had reused it would fail here and nowhere
    // else in the file.
    //
    // What a deletion does to a blast that used the segment is D-27's and is
    // not decided here; nothing points at a segment yet.
    $staff = User::factory()->create();
    $segment = Segment::factory()->create();

    expect(Gate::forUser($staff)->allows('delete', $segment))->toBeTrue()
        ->and(Gate::forUser($staff)->denies(Permission::DeleteSupporters->value))->toBeTrue();
});

test('an owner may delete a segment too, so the ability withholds nothing', function (): void {
    // Paired with the test above to say the whole of what delete() means: it is
    // held by both roles rather than being the one segment ability that
    // discriminates. Without this line, a policy that answered delete() from
    // DeleteSupporters *and* a future grant of DeleteSupporters to Staff would
    // leave the file green while the ability had quietly changed hands.
    $owner = User::factory()->owner()->create();
    $segment = Segment::factory()->create();

    expect(Gate::forUser($owner)->allows('delete', $segment))->toBeTrue();
});
