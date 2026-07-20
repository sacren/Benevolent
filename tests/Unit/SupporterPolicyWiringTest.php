<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Supporters\SupporterPolicy;

/*
 * Two properties of the supporter policy that no behavioural test can assert,
 * because neither of them changes what the policy currently answers.
 *
 * These live in the Unit suite rather than beside the behavioural tests in
 * tests/Campaign because they read source and autoloader state rather than
 * data; filed there they would provision a whole campaign database to inspect
 * a file. The behavioural half is tests/Campaign/SupporterAuthorizationTest.php.
 *
 * This is the first architecture test in the project. It needs no dependency —
 * the plugin ships with Pest — and it was adopted only after its negative
 * control was watched to fail, which is the bar every other guard here had to
 * clear.
 */

arch('the supporter policy answers from a permission, never from a role')
    /*
     * Reading the role directly is the shortest thing to write in a policy and
     * the whole reason the Permission vocabulary exists: `$operator->role ===
     * OperatorRole::Owner` is correct today and silently wrong the day a third
     * role is added, because it names a holder rather than an authority.
     *
     * Measured before this guard was written: rewriting delete() in exactly
     * that form left **every behavioural test green** — the allow, the deny,
     * the disagreement and both middleware tests — because today the two
     * formulations cannot be told apart by their answers. Only this went red.
     * That is what earns it a place; it is not a style rule.
     *
     * Its boundary, measured rather than claimed, because a guard that reads
     * broader than it is gets believed without being checked: it catches the
     * comparison, which needs the import. It does **not** catch
     * `$operator->role->allows(Permission::DeleteSupporters)`, which imports
     * nothing — and that form is not the harm anyway, since it still answers
     * from the permission vocabulary rather than from a role name. What it
     * does bypass is gate registration, which the enum-versus-gate sweep in
     * tests/Campaign/AuthorizationTest.php is the guard for.
     */
    ->expect(SupporterPolicy::class)
    ->not->toUse(OperatorRole::class);

test('nothing occupies the path the gate would otherwise guess', function (): void {
    // What makes #[UsePolicy] on the model load-bearing rather than decorative
    // is that no class sits where Gate::getPolicyFor() would look next.
    //
    // Measured rather than argued, and the first version of this comment was
    // wrong about it. Put a *faithful* duplicate at the first name below and
    // delete the attribute, and the campaign suite goes from **4 red to 1** --
    // the three allow tests recover, because path guessing has quietly supplied
    // a working policy, and only `a supporter is governed by a policy at all`
    // still objects, and only because it names the concrete class rather than
    // asserting that some policy exists. So the attribute-deletion break does
    // not stop working, it loses three quarters of its reach.
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
    // lands on exactly the first of these names.
    expect(class_exists('App\Policies\SupporterPolicy'))->toBeFalse()
        ->and(class_exists('App\Models\Policies\SupporterPolicy'))->toBeFalse();
});
