<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\Permission;
use App\Models\User;

/**
 * Who may see and govern this campaign's roster (D-57, D-58).
 *
 * Registered on User by `#[UsePolicy]` and filed with the module rather than at
 * `App\Policies\UserPolicy`, where the framework's path guessing would find it
 * whether or not the attribute existed (Blueprint §5, v0.19). Here, deleting
 * the attribute turns the allow tests red.
 *
 * **It rides on `ManageOperators` as written, as inviting does (D-58).** That
 * permission's own docblock names inviting, changing what somebody may do and
 * removing them as one authority, and nothing in the product withholds one of
 * those from a role that holds another. Seeing the roster is part of the same
 * authority rather than a fourth: a list whose only purpose is to be acted on
 * is not something to show somebody who may act on none of it, and a campaign
 * directory for everyone is the speculative surface this phase's scope refuses.
 *
 * **Only the abilities a surface asks for.** A policy denies an ability it has
 * no method for exactly as it denies one it refused (Blueprint §5), so each act
 * this module ships adds its method and its allow test in the same edit.
 */
class OperatorPolicy
{
    /**
     * See the roster: who runs this campaign, and who has been invited to.
     */
    public function viewAny(User $operator): bool
    {
        return $operator->can(Permission::ManageOperators->value);
    }

    /**
     * Change what somebody on the roster may do -- themselves included, since
     * an Owner handing over the campaign steps down last. Whether the change
     * would leave nobody who may govern is CampaignGovernance's question, not
     * this one's: this asks only whether the viewer holds the authority.
     */
    public function update(User $operator, User $subject): bool
    {
        return $operator->can(Permission::ManageOperators->value);
    }

    /**
     * Remove somebody else from the roster.
     *
     * **Never oneself, and that is a choice about paths rather than about
     * authority.** An operator leaving is the profile page's act, where the
     * password is asked for and the sign-out happens before the row goes
     * (RemoveOperator's `beforeDeleting`); a second way to leave, missing both,
     * would be the path somebody forgot to guard. And it has a consequence
     * worth stating: since whoever removes somebody governs and stays, removal
     * from the roster can never take a campaign's last governor.
     */
    public function delete(User $operator, User $subject): bool
    {
        return $operator->can(Permission::ManageOperators->value) && ! $operator->is($subject);
    }
}
