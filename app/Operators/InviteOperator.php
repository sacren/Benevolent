<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\User;
use App\Tenancy\CampaignContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * A campaign admitting somebody: one invitation row, and the mail that
 * carries its link.
 *
 * **The row and the send succeed or fail together, and the order is what makes
 * that true.** The row is written, its credential read back, and the message
 * handed to the transport, all inside one transaction. A transport that
 * refuses the message throws, the transaction rolls back, and no invitation
 * exists -- so the campaign is never shown an invitation that did not leave,
 * which is D-56's "must not be got wrong". The reverse is not possible: the
 * credential is minted by the column default (D-54), so there is nothing to
 * put in the link until the row exists.
 *
 * What remains is the gap between "the transport accepted it" and "it
 * arrived", which no code here can close: a bounce comes back later and
 * somewhere this platform does not listen (deferral 30).
 *
 * **Both refusals live here, so every path that invites asks the same
 * question.** The form request and the console command both call
 * refusalFor(); neither restates the rule.
 */
final class InviteOperator
{
    /**
     * Invite somebody, and hand the invitation to the mail transport.
     *
     * @param  User|null  $inviter  who is doing this, or nobody when the platform does (D-53 Axis 3)
     */
    public function __invoke(string $email, OperatorRole $role, ?User $inviter): OperatorInvitation
    {
        return DB::transaction(function () use ($email, $role, $inviter): OperatorInvitation {
            // **An expired invitation to this address is withdrawn first, and
            // that is what lets its lifetime be enforced at read (D-59).** It
            // still holds its credential, so the partial live-invitation index
            // -- which cannot compare against a time -- still counts it, and
            // would refuse this insert at 23505 although its link opens
            // nothing. Withdrawing it is exactly what an Owner would have had
            // to do by hand, and it keeps the row, as every withdrawal does
            // (D-57). Inside this transaction, so a refused send restores it.
            OperatorInvitation::query()
                ->expired()
                ->whereRaw('lower(email) = ?', [Str::lower($email)])
                ->update(['token' => null]);

            $invitation = new OperatorInvitation(['email' => $email]);

            // Named deliberately rather than filled: the authority an
            // invitation grants, and who granted it, are not a request's to say.
            $invitation->role = $role;
            $invitation->invited_by_id = $inviter?->getKey();
            $invitation->invited_by_label = $inviter?->email;

            $invitation->save();

            // The credential is the column's (D-54), so Eloquent does not hold
            // it after the insert. Read back rather than generated here, which
            // is what keeps "no writer escapes the default" true of this one.
            $token = (string) OperatorInvitation::query()->whereKey($invitation->getKey())->value('token');

            Mail::to($email)->send(new OperatorInvitationMessage(
                campaignName: trim((string) tenant('name')),
                role: $role,
                acceptUrl: route('invitation.show', ['invitation' => $token]),
                replyAddress: CampaignContact::address(),
                lifetimeDays: OperatorInvitation::LIFETIME_DAYS,
            ));

            return $invitation;
        });
    }

    /**
     * Why this address cannot be invited now, or null if it can.
     *
     * **Somebody already on the roster, in any casing (D-61).** Fortify folds
     * the address at sign-in, so two operators differing only by case would be
     * one person with one reachable account and one that nobody can sign in
     * to. Compared folded on both sides, because a row written before D-61 may
     * hold a capital.
     *
     * **Somebody with an invitation still open.** The schema refuses this too
     * (`operator_invitations_live_email_unique`, 23505), for the reason it
     * gives: two live links granting possibly different authority, where
     * whichever one is clicked decides what the person becomes. Asked here
     * first so the campaign is told in words rather than by an error page, and
     * left to the index for the race. **An expired invitation is not open**
     * (D-59): its link opens nothing, so it does not stand in the way, and the
     * writer withdraws it before inviting again.
     */
    public static function refusalFor(string $email): ?string
    {
        $folded = Str::lower($email);

        if (User::query()->whereRaw('lower(email) = ?', [$folded])->exists()) {
            return __(':email is already an operator of this campaign.', ['email' => $email]);
        }

        if (OperatorInvitation::query()->whereRaw('lower(email) = ?', [$folded])->live()->exists()) {
            return __(':email already has an invitation to this campaign that has not been used.', ['email' => $email]);
        }

        return null;
    }
}
