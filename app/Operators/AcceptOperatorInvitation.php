<?php

declare(strict_types=1);

namespace App\Operators;

use App\Models\OperatorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns an invitation into an operator of this campaign.
 *
 * **Accepting creates the operator; it does not merely permit a registration.**
 * Step 2 left the schema neutral between the two and said so. The choice is
 * made here on what each would leave open: a permission to register is a
 * second credential-shaped thing sitting between the link and the account, and
 * whatever holds it can register *any* address, so the invitation would name a
 * person while the account it produced named somebody else. Creating the
 * operator from the invitation's own row closes that, because there is no
 * address in the request for anybody to substitute.
 *
 * **The operator's address is the invitation's, folded (D-61).** Folded
 * because Fortify lowercases whatever is typed at sign-in and compares it
 * exactly, so an address stored with a capital can never be signed in with --
 * measured. The invitation keeps the address as the inviter typed it; only the
 * operator's copy is folded, which is the direction D-8 says is recoverable.
 *
 * **The address counts as verified, and that is D-60 answered rather than
 * skipped.** The link reached this request only by being delivered to that
 * address, so the credential already proves control of it. Asking the same
 * person to prove the same fact again by a second mail would be ceremony.
 *
 * **The role is assigned after construction, never passed in.** `role` is not
 * fillable on User because everything a request carries is the requester's to
 * choose, and authority is not. The invitation is the only source of it here,
 * and nothing the accepter posts is read for it.
 *
 * **Recorded in the trail through the observer, with no actor**, because the
 * accepter is unauthenticated at the moment the operator is created and
 * cannot be authenticated before they exist. That is the measured fact D-54
 * rests on, and it is why the invitation row -- which names the inviter --
 * survives being accepted. Written through Eloquent deliberately: a write
 * around it would produce an operator and no entry, silently, which is what
 * D-16 measured for the importer's `upsert()`.
 */
final class AcceptOperatorInvitation
{
    /**
     * @param  array{name: string, password: string}  $details  what the accepter chose for themselves
     */
    public function __invoke(OperatorInvitation $invitation, array $details): User
    {
        $address = Str::lower($invitation->email);

        return DB::transaction(function () use ($invitation, $details, $address): User {
            // **Never a second account for somebody who already is one (D-61).**
            // Compared folded on both sides, because `users.email` is a plain
            // unique index and rows written before D-61 may hold a capital.
            // Refused before the invitation is spent, so the link still works
            // for whatever the campaign decides to do about it.
            if (User::query()->whereRaw('lower(email) = ?', [$address])->exists()) {
                throw ValidationException::withMessages([
                    'email' => __(':email is already an operator of this campaign. Sign in instead.', ['email' => $address]),
                ]);
            }

            // **Claimed before the operator exists, and by the write itself.**
            // The update names the credential it expects, so two requests racing
            // on one link cannot both succeed: the second finds no row still
            // holding it and changes nothing. That is the claim-first shape
            // Blueprint §5 records for work that must happen once, and it spends
            // the token and records the acceptance in one statement, which is
            // the only way the check constraint allows.
            $claimed = OperatorInvitation::query()
                ->whereKey($invitation->getKey())
                ->where('token', $invitation->token)
                ->update(['token' => null, 'accepted_at' => now()]);

            if ($claimed !== 1) {
                throw new NotFoundHttpException;
            }

            $operator = new User([
                'name' => $details['name'],
                'email' => $address,
                'password' => $details['password'],
            ]);

            $operator->role = $invitation->role;

            // The framework's own verb for D-60's answer, and it is the save:
            // it stamps the verification and writes the row in one insert, so
            // the observer sees the operator exactly once, already verified.
            $operator->markEmailAsVerified();

            return $operator;
        });
    }
}
