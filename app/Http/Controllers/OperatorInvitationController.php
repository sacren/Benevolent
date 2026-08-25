<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\OperatorRole;
use App\Http\Requests\Operators\InviteOperatorRequest;
use App\Models\OperatorInvitation;
use App\Models\User;
use App\Operators\InviteOperator;
use App\Operators\WithdrawOperatorInvitation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * A campaign inviting somebody to help run it (D-53 Axis 1 (i)).
 *
 * **The act and its undoing, and nothing around them.** The list of operators
 * and of the invitations still waiting is the roster's (OperatorController);
 * this is the form that admits somebody, and the withdrawal of an invitation
 * nobody has used. Both return the Owner to the roster, where the change shows.
 */
class OperatorInvitationController extends Controller
{
    /**
     * Applied here rather than to the base class, for the reason
     * SegmentController gives.
     */
    use AuthorizesRequests;

    /**
     * Show the form.
     */
    public function create(): Response
    {
        $this->authorize('create', OperatorInvitation::class);

        return Inertia::render('operators/Invite');
    }

    /**
     * Invite them, and hand the invitation to the mail transport.
     *
     * **A transport that refuses the message leaves no invitation**
     * (InviteOperator), and the Owner is told so in words on the form they
     * submitted. The transport's own complaint is deliberately not shown or
     * chained: an SMTP refusal routinely quotes the recipient's address back,
     * which is the failure-record hazard Blueprint §5 records for a driver's
     * exception.
     */
    public function store(InviteOperatorRequest $request, InviteOperator $invite): RedirectResponse
    {
        $this->authorize('create', OperatorInvitation::class);

        /** @var User $inviter */
        $inviter = $request->user();
        $email = (string) $request->validated('email');

        try {
            $invite($email, OperatorRole::from((string) $request->validated('role')), $inviter);
        } catch (TransportExceptionInterface) {
            return back()->withInput()->withErrors([
                'email' => __('The invitation to :email could not be sent, so none was recorded. Try again in a moment.', ['email' => $email]),
            ]);
        }

        // "Sent" means the transport accepted it, which is all the product
        // can know (D-56); the page says what it cannot.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $email])]);

        return to_route('operators.index');
    }

    /**
     * Withdraw an invitation nobody has used.
     *
     * **An invitation already used or withdrawn is refused in words**, on the
     * roster the Owner is looking at, rather than as an error page: by the time
     * they click, the invitee may have accepted it a moment earlier, and that is
     * news rather than a fault. The row is untouched either way.
     */
    public function destroy(OperatorInvitation $invitation, WithdrawOperatorInvitation $withdraw): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        if (! $withdraw($invitation)) {
            return to_route('operators.index')->withErrors([
                'invitation' => __('The invitation to :email had already been used or withdrawn, so nothing changed.', ['email' => $invitation->email]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The invitation to :email is withdrawn. Its link no longer works.', ['email' => $invitation->email])]);

        return to_route('operators.index');
    }
}
