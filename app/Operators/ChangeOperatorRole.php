<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\OperatorRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An operator is given different authority within this campaign (D-57).
 *
 * **The role is assigned, never filled.** `role` is not fillable on User,
 * because everything a request carries is the requester's to choose and
 * authority is not; the roster form posts a role, and this writer is the one
 * place that reads it, by name, after the Owner's authority to change it has
 * been asked. Nothing else the request carries reaches the operator.
 *
 * **Recorded by the observer, with the Owner who did it as the actor.** Saved
 * through Eloquent in an authenticated request, so OperatorAuditObserver
 * writes an `operator-role-changed` entry naming both the operator and whoever
 * changed them -- the first path in the application that produces one.
 *
 * **Stepping down asks CampaignGovernance the same two questions leaving
 * does**: whether it leaves the campaign with nobody who may govern it, and
 * which invitations stop standing now that their sender no longer governs.
 * Promotion asks neither, since it takes nothing away from anybody.
 */
final class ChangeOperatorRole
{
    /**
     * Give them this role, unless that would leave the campaign ungovernable.
     *
     * @param  string  $refusal  what to tell whoever asked, in their own words, if it would
     *
     * @throws ValidationException
     */
    public function __invoke(User $operator, OperatorRole $role, string $refusal): void
    {
        if ($operator->role === $role) {
            return;
        }

        DB::transaction(function () use ($operator, $role, $refusal): void {
            if (CampaignGovernance::stepsDown($operator, $role)) {
                // Withdrawn first, for the reason RemoveOperator gives; a
                // refusal below rolls it back with everything else.
                CampaignGovernance::withdrawInvitationsSentBy($operator);

                CampaignGovernance::refuseToStepDownUngoverned($operator, $refusal);
            }

            $operator->role = $role;
            $operator->save();
        });
    }
}
