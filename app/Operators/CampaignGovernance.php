<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\OperatorRole;
use App\Authorization\Permission;
use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What keeps a campaign governable while its roster changes (§7 criterion 3).
 *
 * **The door this closes has two entrances, and both were measured before this
 * class was written.** The first is the one D-52 rests on: the last operator
 * who may govern leaves while somebody else stays -- and the campaign is then
 * run by people none of whom may admit, promote or remove anybody, which the
 * platform will not repair either, because `campaign:invite-owner` refuses a
 * campaign that has operators. The second needs nobody else present at all: a
 * sole Owner who leaves an empty campaign behind looks reclaimable, and then a
 * Staff invitation they sent before leaving is accepted, and the campaign has
 * one Staff operator and no Owner. Measured at Phase 6 Step 4 through the
 * shipped surfaces, both times.
 *
 * So there are two rules here and each answers one entrance. Nothing is asked
 * about Staff or Owner by name: the question is who holds ManageOperators,
 * derived from the role list, so a third role that governs is counted without
 * this class being told about it.
 *
 * **Both must run inside the writer's transaction**, which is why they are
 * static calls rather than a check a controller makes first. The governors
 * are read under a row lock, so two Owners stepping down at the same moment
 * cannot each see the other still in office and both succeed.
 */
final class CampaignGovernance
{
    /**
     * Refuse an operator's departure if it would leave the operators who stay
     * with nobody who may govern them.
     *
     * Only a governor can trip this: somebody who does not govern leaves the
     * governors where they were, and a campaign that is already ungoverned is
     * not made worse by losing a non-governor -- it is brought closer to
     * empty, which is the state the platform can repair. **And a governor
     * leaving an otherwise empty campaign is allowed**, for that reason: nobody
     * is left to be governed, and `campaign:invite-owner` accepts the campaign
     * again. The second entrance is closed by withdrawInvitationsSentBy(), not
     * here.
     *
     * @throws ValidationException
     */
    public static function refuseToLeaveUngoverned(User $operator, string $message): void
    {
        if (! self::governs($operator->role)) {
            return;
        }

        // Every governor is locked, this one included, and in one order.
        // Locking only the *others* would let two Owners stepping down at once
        // each lock the other's row and deadlock; locking the whole set makes
        // the second writer wait here, and then read the roster the first one
        // left behind rather than the one it started with.
        $governors = User::query()
            ->whereIn('role', self::governingRoles())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($governors->contains(fn (int $id): bool => $id !== $operator->getKey())) {
            return;
        }

        if (User::query()->whereKeyNot($operator->getKey())->exists()) {
            throw ValidationException::withMessages(['operator' => $message]);
        }
    }

    /**
     * Withdraw every invitation this operator sent that has not been used.
     *
     * **An invitation stands on the authority of whoever sent it**, and once
     * they are gone it is no longer an act of the campaign (§7 criterion 2).
     * The measured reason it cannot be left for somebody to tidy: a sole
     * Owner's Staff invitation, accepted after they left, is the door's second
     * entrance. Invitations the platform sent (`invited_by_id` null) are
     * nobody's authority but the platform's and are untouched.
     *
     * **A withdrawn invitation keeps its row** -- no credential and no
     * acceptance, the third state D-54's check constraint deliberately leaves
     * legal -- so the record of who invited whom, when and with what
     * authority survives it exactly as it survives acceptance.
     *
     * Call it **before** refuseToLeaveUngoverned() in a removal. This update
     * takes the row locks an acceptance's own claim needs, so an acceptance
     * racing the departure either finishes first -- and is then counted as
     * somebody who stays -- or finds its invitation already withdrawn.
     */
    public static function withdrawInvitationsSentBy(User $operator): int
    {
        return OperatorInvitation::query()
            ->where('invited_by_id', $operator->getKey())
            ->whereNotNull('token')
            ->update(['token' => null]);
    }

    /**
     * Whether a role carries the authority this class protects.
     */
    public static function governs(OperatorRole $role): bool
    {
        return $role->allows(Permission::ManageOperators);
    }

    /**
     * The stored values of every role that governs.
     *
     * @return list<string>
     */
    private static function governingRoles(): array
    {
        return array_values(array_map(
            fn (OperatorRole $role): string => $role->value,
            array_filter(OperatorRole::cases(), self::governs(...)),
        ));
    }
}
