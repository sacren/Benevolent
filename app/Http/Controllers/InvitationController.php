<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Operators\AcceptInvitationRequest;
use App\Models\OperatorInvitation;
use App\Operators\AcceptOperatorInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How somebody a campaign invited becomes one of its operators.
 *
 * **The second surface this product serves to a person with no account**, and
 * it takes the unsubscribe page's shape for the unsubscribe page's reasons:
 * outside the `auth` group, inside `tenant`, a GET that renders and a POST that
 * acts, and authority carried by a credential in the URL rather than by a
 * policy. There is no policy here because a policy decides what a signed-in
 * operator may do, and this visitor is not one yet.
 *
 * **The credential's scope is the campaign's own database.** The campaign is
 * resolved from the Host header before this controller runs, so the binding
 * below reads this campaign's `operator_invitations` and no other, and a link
 * minted in one campaign matches no row in another for the reason a supporter
 * does not. That is DEC-1's isolation doing the work, which is why D-53 chose a
 * stored invitation over a signed URL (Axis 1 (i)); Step 1 re-ran the property
 * rather than inheriting it.
 *
 * **An unknown or spent link is a 404, and the binding answers it before
 * anything else runs.** The route binds `{invitation:token}`, so a link whose
 * token matches no row -- a stranger guessing, another campaign's link, one
 * already used -- is refused by the router before the form request validates
 * anything. A spent invitation holds no token (D-54), so "already used" needs
 * no status check: it is simply not findable by its link. A malformed token
 * never reaches the query at all; `whereUuid` answers 404 first, which matters
 * because the column is `uuid` and PostgreSQL raises 22P02 on anything else.
 */
class InvitationController extends Controller
{
    /**
     * Show the invitation, without accepting it.
     *
     * A GET that only renders, for D-16(b)'s measured reason: mail scanners and
     * link prefetchers issue GETs against every URL in a message, so a link that
     * acted on GET would spend invitations nobody opened.
     */
    public function show(OperatorInvitation $invitation): Response
    {
        return Inertia::render('Invitation', [
            'token' => (string) $invitation->token,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            // From the registry row tenancy is already holding, so it costs no
            // query -- and it is what tells somebody arriving from an inbox whose
            // campaign this is, on a page that carries no platform chrome.
            'campaignName' => trim((string) tenant('name')),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Accept the invitation: become an operator, and be signed in as one.
     *
     * Signed in straight away, as Fortify's own registration did, because the
     * person has just chosen the password they would otherwise be asked for.
     * The session id is regenerated for the reason Fortify regenerates it on
     * every sign-in: an id handed out before authentication must not survive it.
     */
    public function store(AcceptInvitationRequest $request, OperatorInvitation $invitation, AcceptOperatorInvitation $accept): RedirectResponse
    {
        /** @var array{name: string, password: string} $details */
        $details = $request->validated();

        $operator = $accept($invitation, $details);

        Auth::login($operator);

        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
