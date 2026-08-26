<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\Permission;
use App\Models\OperatorInvitation;
use App\Models\User;

/**
 * Who may admit somebody to this campaign, and take the invitation back.
 *
 * Registered on the model by `#[UsePolicy]` and filed with the module, where
 * the framework's path guessing will not find it (Blueprint §5, v0.19): a
 * policy at the conventional path makes the attribute decorative, so deleting
 * it would leave every test green. Here, deleting it turns the allow tests red.
 *
 * **Both abilities ride on `ManageOperators` as written (D-58).** That
 * permission's own docblock names inviting as the first of its three acts, so
 * an invitation needs no vocabulary of its own, and withdrawing one is the same
 * act undone. What would split them -- a role trusted to invite but not to
 * remove -- has no consumer; OperatorPolicy answers the roster's own acts the
 * same way.
 *
 * No `viewAny`: the invitations nobody has used or withdrawn -- waiting, or
 * past their lifetime and marked so -- are listed on the roster, and seeing it
 * is OperatorPolicy's to answer, once, for the page as a whole. No
 * `update` either, since nothing changes an invitation once it is sent -- a
 * wrong one is withdrawn and sent again. A policy method with no call site is
 * the guess Blueprint §5 says a policy should not contain.
 */
class OperatorInvitationPolicy
{
    /**
     * Invite somebody to become an operator of this campaign.
     */
    public function create(User $operator): bool
    {
        return $operator->can(Permission::ManageOperators->value);
    }

    /**
     * Withdraw an invitation nobody has used yet.
     *
     * The same authority as sending one (D-58): an operator trusted to admit
     * somebody is trusted to change their mind before the person arrives, and
     * nothing in the product holds one of those without the other.
     */
    public function delete(User $operator, OperatorInvitation $invitation): bool
    {
        return $operator->can(Permission::ManageOperators->value);
    }
}
