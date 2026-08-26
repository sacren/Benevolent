<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\OperatorRole;
use App\Http\Requests\Operators\ChangeOperatorRoleRequest;
use App\Models\OperatorInvitation;
use App\Models\User;
use App\Operators\ChangeOperatorRole;
use App\Operators\RemoveOperator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A campaign's roster: who runs it, and who has been invited to (D-57).
 *
 * **Authority is asked of OperatorPolicy**, never of a permission string and
 * never of a `can:` middleware on the route, for the reason every controller
 * here gives: the ability a route checks and the ability its action performs
 * cannot drift apart.
 *
 * **Each operator's role is sent as data, and that is not the branch the
 * shared props refuse.** HandleInertiaRequests withholds the *signed-in*
 * operator's role so that no control is hidden or shown because of what
 * somebody is called rather than what they may do. Here the role is the thing
 * on the page -- what each person on the roster is -- and whether the viewer
 * may see it at all has already been settled by the policy.
 *
 * **Who admitted each operator is read from the invitation they accepted, and
 * nothing is invented where there is none (§7 criterion 4).** An operator who
 * existed before invitations were recorded -- the demo campaign's seeded Owner
 * is one -- has no such row, and the page says so rather than guessing, the
 * way a blast that predates recording reads "not recorded" rather than 0. The
 * match is by address, folded on both sides, because an invitation names an
 * address and never a user (D-54 holds no `user_id`), so an operator who has
 * changed their address since reads "not recorded" too: the record exists,
 * but nothing connects it to them, and the page does not pretend otherwise.
 *
 * **The trail is not read here** (deferral 9's reading half). What the roster
 * needs -- who someone is, what they may do, who invited them -- is the
 * roster's and the invitation's, and neither needs the history of every change.
 */
class OperatorController extends Controller
{
    /**
     * Applied here rather than to the base class, for the reason
     * SegmentController gives.
     */
    use AuthorizesRequests;

    /**
     * Show the roster.
     *
     * **Unpaginated, and the trigger names the quantity that grows.** Two
     * queries over two small tables and one over the accepted invitations for
     * the addresses on the page, whatever the roster's size -- so the rows on
     * the page are the cost. **Trigger to revisit:** the first campaign whose
     * operators are counted in hundreds, which is a staff list no campaign
     * this product has served is near.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $operators = User::query()->orderBy('name')->orderBy('id')->get();

        $admittedBy = $this->acceptedInvitationsFor($operators->map(fn (User $operator): string => $operator->email)->all());

        return Inertia::render('operators/Index', [
            'operators' => $operators->map(fn (User $operator): array => [
                'id' => $operator->getKey(),
                'name' => $operator->name,
                'email' => $operator->email,
                'role' => $operator->role->value,
                'is_you' => $operator->is($request->user()),
                'admitted' => $this->admission($admittedBy[Str::lower($operator->email)] ?? null),
            ])->all(),
            // Unused and not withdrawn: an accepted invitation is an operator
            // above, and a withdrawn one is a record rather than something to
            // act on. One past its lifetime (D-59) is listed too, because it
            // still holds its credential and is still the campaign's to withdraw.
            'invitations' => OperatorInvitation::query()
                ->whereNotNull('token')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (OperatorInvitation $invitation): array => [
                    'id' => $invitation->getKey(),
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'invited_by' => $invitation->invited_by_label,
                ])->all(),
        ]);
    }

    /**
     * Give somebody on the roster a different role.
     *
     * **A step down that would leave nobody who may govern is refused in
     * words on the roster** (§7 criterion 3). The only operator who can reach
     * that refusal is the last Owner acting on their own row, since nobody
     * else governs to act on them; the message is written to them.
     */
    public function update(ChangeOperatorRoleRequest $request, User $operator, ChangeOperatorRole $change): RedirectResponse
    {
        $this->authorize('update', $operator);

        $role = OperatorRole::from((string) $request->validated('role'));

        $change(
            $operator,
            $role,
            __('You are the last operator who can govern this campaign. Make somebody else an Owner before stepping down.'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email is now :role.', [
            'email' => $operator->email,
            'role' => $role === OperatorRole::Owner ? __('an Owner') : __('Staff'),
        ])]);

        return to_route('operators.index');
    }

    /**
     * Remove somebody else from the roster.
     *
     * **An access change, not an erasure** (RemoveOperator), and recorded in
     * the trail with this Owner as the actor, since the request stays
     * authenticated throughout -- unlike leaving, which signs its operator
     * out before the row goes and so names nobody. Their unused invitations
     * are withdrawn with them.
     *
     * The refusal RemoveOperator can give is unreachable from here, since the
     * Owner acting governs and stays; it is passed anyway, because the writer
     * asks every caller the same question rather than trusting each to know
     * which answers it cannot get.
     */
    public function destroy(User $operator, RemoveOperator $remove): RedirectResponse
    {
        $this->authorize('delete', $operator);

        $remove(
            $operator,
            __('Removing :email would leave this campaign with nobody who can govern it.', ['email' => $operator->email]),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email is no longer an operator of this campaign.', ['email' => $operator->email])]);

        return to_route('operators.index');
    }

    /**
     * The most recent accepted invitation for each of these addresses, keyed by
     * the folded address.
     *
     * The most recent, because somebody can join, leave and be invited again,
     * and it is the invitation that admitted them *this* time that says who
     * did. Accepted only: a live or withdrawn invitation to somebody's address
     * admitted nobody.
     *
     * @param  array<int, string>  $addresses
     * @return array<string, OperatorInvitation>
     */
    private function acceptedInvitationsFor(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        return OperatorInvitation::query()
            ->whereNotNull('accepted_at')
            ->whereIn(DB::raw('lower(email)'), array_map(Str::lower(...), $addresses))
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (OperatorInvitation $invitation): string => Str::lower($invitation->email))
            ->all();
    }

    /**
     * How an operator came to be one, as the page shows it.
     *
     * Null when nothing records it. An invitation with no inviter was sent by
     * the platform, which is the only writer that names nobody
     * (`campaign:invite-owner`).
     *
     * @return array{by: string|null}|null
     */
    private function admission(?OperatorInvitation $invitation): ?array
    {
        if ($invitation === null) {
            return null;
        }

        return ['by' => $invitation->invited_by_label];
    }
}
