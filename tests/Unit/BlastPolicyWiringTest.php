<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Blasts\BlastPolicy;

/*
 * Two properties of the blast policy that no behavioural test can assert,
 * because neither of them changes what the policy currently answers.
 *
 * These live in the Unit suite rather than beside the behavioural tests in
 * tests/Campaign for the reason SupporterPolicyWiringTest gives: they read
 * source and autoloader state rather than data, and filed there they would
 * provision a whole campaign database to inspect a file. The behavioural half
 * is tests/Campaign/BlastAuthorizationTest.php.
 */

arch('the blast policy answers from a permission, never from a role')
    /*
     * Reading the role directly is the shortest thing to write in a policy and
     * the whole reason the Permission vocabulary exists: `$operator->role ===
     * OperatorRole::Owner` is correct today and silently wrong the day a third
     * role is added, because it names a holder rather than an authority. The
     * temptation is sharper here than it was for supporters, because `send` is
     * the one ability in this module the roles disagree about, so an Owner
     * comparison would read as if it said exactly what is meant.
     *
     * Measured before this guard was trusted, on this policy rather than
     * inherited from the supporter one: rewriting send() in exactly that form
     * left **every behavioural test green** -- both pairings, the wiring test
     * and the committed-state test -- because today the two formulations cannot
     * be told apart by their answers. Only this went red.
     *
     * Its boundary, measured rather than claimed, because a guard that reads
     * broader than it is gets believed without being checked: it catches the
     * comparison, which needs the import. It does **not** catch
     * `$operator->role->allows(Permission::SendBlasts)`, which imports nothing
     * -- and that form is not the harm anyway, since it still answers from the
     * permission vocabulary rather than from a role name. What it does bypass
     * is gate registration, which the enum-versus-gate sweep in
     * tests/Campaign/AuthorizationTest.php is the guard for.
     */
    ->expect(BlastPolicy::class)
    ->not->toUse(OperatorRole::class);

test('nothing occupies the path the gate would otherwise guess', function (): void {
    // What makes #[UsePolicy] on the model load-bearing rather than decorative
    // is that no class sits where Gate::getPolicyFor() would look next.
    //
    // Measured on this model rather than argued from the supporter case, and
    // the numbers are the reason this test exists. Put a faithful duplicate at
    // the first name below and delete the attribute, and
    // Gate::getPolicyFor(Blast::class) hands back App\Policies\BlastPolicy --
    // path guessing has quietly supplied a working policy -- leaving **1 of the
    // 5 campaign tests red**, and only because that one names the concrete
    // class rather than asserting that some policy exists. So the
    // attribute-deletion break does not stop working; it loses most of its
    // reach.
    //
    // What this test adds is therefore not last-line-of-defence -- it is timing
    // and diagnosis. It fires on the duplicate the moment it appears, while the
    // attribute is still present and everything still behaves, rather than
    // waiting for a second change to combine with it; and it names the cause,
    // where the surviving campaign test would send a reader hunting for a
    // deleted attribute that is in fact still there.
    //
    // The drift is realistic rather than invented: `make:policy` writes to
    // app/Policies/ by default, so a policy generated the conventional way
    // lands on exactly the first of these names -- and this application has no
    // app/Policies/ directory at all, so creating one is the visible moment.
    expect(class_exists('App\Policies\BlastPolicy'))->toBeFalse()
        ->and(class_exists('App\Models\Policies\BlastPolicy'))->toBeFalse();
});
