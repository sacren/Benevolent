<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Segments\SegmentPolicy;

/*
 * Properties of the segment policy that no behavioural test can assert, because
 * none of them changes what the policy currently answers.
 *
 * These live in the Unit suite rather than beside the behavioural tests in
 * tests/Campaign for the reason the supporter and blast wiring tests give: they
 * read source and autoloader state rather than data, and filed there they would
 * provision a whole campaign database to inspect a file. The behavioural half
 * is tests/Campaign/SegmentAuthorizationTest.php.
 *
 * **One defect in this policy is guarded by nothing, and saying so is better
 * than a guard that cannot fail.** Because no segment ability discriminates
 * (D-25), a method rewritten to `return true` answers identically to the one it
 * replaced for every operator this application can construct. Measured rather
 * than reasoned about: all four bodies replaced with `return true` leaves
 * **413 of 413 green**, and neither PHPStan nor Pint objects to the import left
 * unused behind it.
 *
 * A third expectation was drafted to catch it -- `expect(SegmentPolicy::class)
 * ->toUse(Permission::class)` -- and was **measured and rejected**, which is why
 * it is described here rather than shipped. It passes against the mutation
 * above, because `toUse()` is satisfied by the import and no linter removes an
 * unused one; it goes red only when the import is deleted as well, which is not
 * the defect it was aimed at. A guard that is green against the thing it names
 * is worse than no guard, since it invites the reader to stop looking.
 *
 * **What actually closes it is the same condition that gives segments their own
 * permissions.** The rewrite is invisible only while no role is refused
 * anything here; the first segment ability that discriminates makes it visible
 * to an ordinary allow/deny pair, and D-25 records the trigger for that as D-26
 * giving a blast a pointer at a segment.
 */

arch('the segment policy answers from a permission, never from a role')
    /*
     * Reading the role directly is the shortest thing to write in a policy and
     * the whole reason the Permission vocabulary exists: `$operator->role ===
     * OperatorRole::Owner` names a holder rather than an authority, and is
     * silently wrong the day a third role appears.
     *
     * **Which rewrite this is the last line of defence for is different here
     * than on the other two policies, and both halves were measured rather than
     * assumed.** Rewriting all four methods as `$operator->role ===
     * OperatorRole::Owner` reddens **5 of 413** -- this expectation and four
     * campaign tests -- because no segment ability discriminates, so an Owner
     * comparison refuses Staff outright and the behavioural file reports it
     * unaided. The form that keeps today's answers is a comparison naming both
     * roles, and that one reddens **1 of 413**: this expectation alone. With
     * this file deleted it reddens **0 of 411**. So the guard is genuinely the
     * last line of defence, and for a narrower rewrite than the supporter and
     * blast files record.
     *
     * Its boundary, measured rather than claimed: it catches the comparison,
     * which needs the import. It does **not** catch
     * `$operator->role->allows(Permission::EditSupporters)`, which imports
     * nothing -- and that form is not the harm anyway, since it still answers
     * from the permission vocabulary. What it does bypass is gate registration,
     * which the enum-versus-gate sweep in tests/Campaign/AuthorizationTest.php
     * is the guard for.
     */
    ->expect(SegmentPolicy::class)
    ->not->toUse(OperatorRole::class);

test('nothing occupies the path the gate would otherwise guess', function (): void {
    // What makes #[UsePolicy] on the model load-bearing rather than decorative
    // is that no class sits where Gate::getPolicyFor() would look next.
    //
    // Measured on this model rather than argued from the other two, because a
    // resolution order is the kind of claim that gets believed without being
    // checked. With nothing anywhere, Gate::getPolicyFor(Segment::class)
    // returns null. Declare a faithful duplicate at the first name below and
    // the gate hands it back *with no attribute present at all*, so the
    // attribute-deletion break loses most of its reach the moment such a class
    // exists: deleting the attribute alone reddens **5 of 413**, every
    // behavioural test in this module, while deleting it with the duplicate in
    // place reddens **2** -- this test, and the one campaign test that names the
    // concrete class rather than asserting that some policy exists.
    //
    // What this test adds is therefore timing and diagnosis rather than
    // last-line-of-defence: with the attribute still present the duplicate
    // reddens **1 of 413**, this test alone, while everything still behaves --
    // so it fires the moment the drift appears rather than waiting for a second
    // change to combine with it, and it names the cause, where the surviving
    // campaign test would send a reader hunting for a deleted attribute that is
    // in fact still there.
    //
    // The drift is realistic rather than invented: `make:policy` writes to
    // app/Policies/ by default, so a policy generated the conventional way
    // lands on exactly the first of these names -- and this application still
    // has no app/Policies/ directory at all, so creating one is the visible
    // moment.
    expect(class_exists('App\Policies\SegmentPolicy'))->toBeFalse()
        ->and(class_exists('App\Models\Policies\SegmentPolicy'))->toBeFalse();
});
