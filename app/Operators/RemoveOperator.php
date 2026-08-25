<?php

declare(strict_types=1);

namespace App\Operators;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An operator stops being one of this campaign's operators.
 *
 * **An access change, not an erasure request** -- Phase 0's decision, and the
 * reason this is a delete and nothing more. The trail keeps the entry naming
 * them, the invitations they sent and the one that admitted them keep their
 * rows, and the blasts, segments and imports they made stay the campaign's,
 * with the pointer to them emptied by the schema. Their passkeys go with them,
 * because those are theirs rather than the campaign's.
 *
 * **Every path that removes an operator comes through here**, so each asks
 * CampaignGovernance the same two questions in the same transaction: whether
 * this leaves somebody with nobody to govern them, and which invitations stop
 * standing now that their sender has gone.
 *
 * Written through Eloquent deliberately, so OperatorAuditObserver records the
 * removal; a delete around the model would remove an operator and write no
 * entry, silently.
 */
final class RemoveOperator
{
    /**
     * Remove them, unless that would leave the campaign ungovernable.
     *
     * @param  string  $refusal  what to tell whoever asked, in their own words, if it would
     * @param  (Closure(): void)|null  $beforeDeleting  run once the removal is certain and before the row goes
     *
     * @throws ValidationException
     */
    public function __invoke(User $operator, string $refusal, ?Closure $beforeDeleting = null): void
    {
        DB::transaction(function () use ($operator, $refusal, $beforeDeleting): void {
            // Withdrawn first: see CampaignGovernance::withdrawInvitationsSentBy()
            // for why the order is what closes the race with an acceptance.
            // Refused below, the withdrawal rolls back with everything else.
            CampaignGovernance::withdrawInvitationsSentBy($operator);

            CampaignGovernance::refuseToLeaveUngoverned($operator, $refusal);

            // Self-removal signs the operator out here, and it has to happen
            // before the delete rather than after: logging out cycles the
            // remember token through a save, and a model that has just been
            // deleted would be INSERTed back by it.
            if ($beforeDeleting !== null) {
                $beforeDeleting();
            }

            $operator->delete();
        });
    }
}
