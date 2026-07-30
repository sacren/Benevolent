<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blasts\BlastAudience;
use App\Http\Requests\Blasts\ComposeBlastRequest;
use App\Models\Blast;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The messages a campaign has written to the people on its list.
 *
 * Sits at the root of app/Http/Controllers/ for the reason SupporterController
 * does: the Settings/ sub-directory exists because two controllers share that
 * concern, and one controller needs no directory to hold it. The module's own
 * vocabulary lives in app/Blasts/, where the framework reads nothing.
 *
 * **Authority is asked of the policy, never of a permission string**, and never
 * of a `can:` middleware on the route. The mapping from an ability to a
 * permission is what BlastPolicy exists to own, and a controller naming
 * Permission::EditBlasts directly would be a second copy of that mapping, free
 * to drift from the first. routes/tenant.php says the same thing from its side.
 */
class BlastController extends Controller
{
    /**
     * Laravel 11 emptied the base Controller, so authorize() is not inherited
     * from anywhere and $this->authorize() would be a fatal call to an
     * undefined method. The trait is applied here rather than to the base class
     * for SupporterController's reason: raising it would hand every present and
     * future controller an authorization surface on behalf of the ones that
     * asked.
     */
    use AuthorizesRequests;

    /**
     * Show the messages this campaign has written, newest first.
     *
     * **Unpaginated, unlike the supporter list, and the difference is in how
     * the two lists grow rather than in how they are read.** A supporter list
     * is filled by import: one file writes thousands of rows inside a single
     * transaction, which is what took that page to 680 MB at a quarter-million
     * rows and forced pagination at Phase 1 Step 6. A blast is written by hand,
     * one at a time, by somebody composing a message -- so the list is bounded
     * by human effort rather than by a file, and a campaign with hundreds of
     * blasts has been running for years. **Trigger to revisit:** the first
     * campaign whose blast list needs more than one screen, or the first thing
     * that creates blasts other than an operator typing one.
     *
     * The id tie-break is kept even so, and for the reason it is kept on the
     * supporter list rather than by imitation: `created_at` is a timestamp two
     * rows can share, and an order that is not total lets them swap places
     * between requests. That costs nothing here and stops meaning nothing the
     * moment paging arrives.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Blast::class);

        return Inertia::render('blasts/Index', [
            'blasts' => Blast::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * Show the form for writing a blast.
     */
    public function create(): Response
    {
        $this->authorize('create', Blast::class);

        return Inertia::render('blasts/Create');
    }

    /**
     * Write a blast, and open it for aiming.
     *
     * Redirects to the edit page rather than back to the list, which is the one
     * place this controller departs from SupporterController's shape. A
     * supporter is finished when it is saved; a blast is not, because the
     * number of people it would reach is the thing the operator most needs to
     * see and it cannot be shown until there is a blast to compute it for.
     */
    public function store(ComposeBlastRequest $request): RedirectResponse
    {
        $this->authorize('create', Blast::class);

        $blast = new Blast($request->composed());

        // Authorship is stamped here rather than mass-assigned, and `operator_id`
        // is deliberately absent from the model's fillable list: a form able to
        // set it could put somebody else's name on a message that went out.
        $blast->operator_id = $request->user()?->getKey();

        $blast->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blast saved as a draft.')]);

        return to_route('blasts.edit', $blast);
    }

    /**
     * Show the form for changing a blast still in draft, and who it would reach.
     *
     * Governed by `update` rather than by a `view` ability, and that is a
     * decision rather than an oversight -- the same one SupporterController
     * records. This module ships no read-only page for one blast; the only
     * reason to open one is to change it. It matters because BlastPolicy
     * answers exactly the abilities it has methods for and denies every other
     * one silently, indistinguishably from a considered refusal: the day a
     * read-only page arrives, `view` and its allow test have to be added in the
     * same edit or the 403 will look deliberate.
     */
    public function edit(Blast $blast): Response|RedirectResponse
    {
        $this->authorize('update', $blast);

        if ($refusal = $this->refuseCommitted($blast)) {
            return $refusal;
        }

        return Inertia::render('blasts/Edit', [
            'blast' => $blast,

            // **A prediction, not a promise, and the page says so in those
            // words.** The audience is a rule evaluated again when sending
            // starts (D-14), so somebody who unsubscribes between now and then
            // is correctly left out and this number moves. Materializing a list
            // here would make the number exact and the send wrong, which is the
            // trade D-14 refused.
            'audienceSize' => BlastAudience::size($blast),
        ]);
    }

    /**
     * Change a blast still in draft.
     */
    public function update(ComposeBlastRequest $request, Blast $blast): RedirectResponse
    {
        $this->authorize('update', $blast);

        if ($refusal = $this->refuseCommitted($blast)) {
            return $refusal;
        }

        $blast->update($request->composed());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blast updated.')]);

        // Back to the same page, so the recipient count is recomputed against
        // the aim just saved. Sending the operator to the list would show them
        // the aim they chose and not what it now reaches.
        return to_route('blasts.edit', $blast);
    }

    /**
     * Turn away an attempt to change a blast the campaign has already committed.
     *
     * **This is a statement about state, and it deliberately lives here rather
     * than in the policy.** BlastPolicy answers authority and never state, on
     * the grounds recorded in its own docblock: a refusal that means both "you
     * have no authority" and "this already went" tells an Owner the one thing
     * that is not true and withholds the one diagnosis they need. So the policy
     * still says an operator with EditBlasts may update a blast, and this says
     * there is nothing left to update.
     *
     * A redirect carrying the reason, rather than a 403 or a 404. The blast
     * exists and the operator may edit blasts; what has changed is the blast.
     *
     * Note the boundary this draws and the one it does not. Whether a *send* may
     * run twice is Step 4's, and is held by the check constraint on `blasts` and
     * by the lock the sending path will carry -- neither of which is a courtesy,
     * and neither of which this method is.
     */
    private function refuseCommitted(Blast $blast): ?RedirectResponse
    {
        if (! $blast->status->isCommitted()) {
            return null;
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('That blast has been committed to sending, so it can no longer be changed.'),
        ]);

        return to_route('blasts.index');
    }
}
