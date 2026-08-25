<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\OperatorRole;
use App\Http\Requests\Operators\InviteOperatorRequest;
use App\Models\OperatorInvitation;
use App\Models\User;
use App\Operators\InviteOperator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * A campaign inviting somebody to help run it (D-53 Axis 1 (i)).
 *
 * **The act and nothing around it.** The list of operators and of the
 * invitations still waiting is the roster's (OperatorController); this is the
 * form that admits somebody, and a sent invitation returns the Owner to the
 * roster, where it now appears among the ones not yet used.
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
}
