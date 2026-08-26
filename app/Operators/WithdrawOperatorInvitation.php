<?php

declare(strict_types=1);

namespace App\Operators;

use App\Models\OperatorInvitation;

/**
 * A campaign taking back an invitation nobody has used (D-57).
 *
 * **The row stays; only its credential goes.** A withdrawn invitation is the
 * third state D-54's check constraint deliberately leaves legal -- no
 * credential and no acceptance -- so the record of who was invited, by whom,
 * as what and when survives the withdrawal exactly as it survives acceptance.
 * Deleting it instead would leave the trail as the only record that an
 * invitation ever existed, and Step 3 declined an `OperatorInvited` case
 * precisely because this row already is that record.
 *
 * **Withdrawing is also how a lost invitation is sent again.** The inviting
 * writer refuses a second live invitation to one address (the partial unique
 * index says why), so an invitation that never arrived stands in the way until
 * it is withdrawn -- or until its lifetime runs out (D-59), when inviting the
 * address again withdraws it first. Either way the address may then be
 * invited afresh.
 *
 * **Claimed by the write itself**, as an acceptance is: the update names the
 * credential's presence, so an Owner withdrawing an invitation at the moment
 * its invitee accepts it cannot both succeed. Whichever statement runs second
 * finds no credential and changes nothing.
 */
final class WithdrawOperatorInvitation
{
    /**
     * Withdraw it, and say whether this call was the one that did.
     */
    public function __invoke(OperatorInvitation $invitation): bool
    {
        return OperatorInvitation::query()
            ->whereKey($invitation->getKey())
            ->whereNotNull('token')
            ->update(['token' => null]) === 1;
    }
}
