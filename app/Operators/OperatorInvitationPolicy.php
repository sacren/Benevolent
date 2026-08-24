<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\Permission;
use App\Models\User;

/**
 * Who may admit somebody to this campaign.
 *
 * Registered on the model by `#[UsePolicy]` and filed with the module, where
 * the framework's path guessing will not find it (Blueprint §5, v0.19): a
 * policy at the conventional path makes the attribute decorative, so deleting
 * it would leave every test green. Here, deleting it turns the allow tests red.
 *
 * **One ability, and it rides on `ManageOperators` as written (D-58's likely
 * answer, taken early for the one act this step ships).** That permission's
 * own docblock names inviting as the first of its three acts, so an invitation
 * needs no vocabulary of its own. What would split it -- a role trusted to
 * invite but not to remove -- has no consumer, and D-58 at Step 4 is where
 * the other two acts are asked the same question.
 *
 * No `view`, `update` or `delete`: this step ships no list of invitations and
 * no way to withdraw one, which are D-57's. A policy method with no call site
 * is the guess Blueprint §5 says a policy should not contain.
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
}
