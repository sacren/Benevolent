<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Segments\NameSegmentRequest;
use App\Models\Segment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The narrowings a campaign has named for its own supporter list.
 *
 * Sits at the root of app/Http/Controllers/ for the reason SupporterController
 * and BlastController both do: the Settings/ sub-directory exists because two
 * controllers share that concern, and one controller needs no directory to hold
 * it. The module's own vocabulary lives in app/Segments/, where the framework
 * reads nothing.
 *
 * **Authority is asked of the policy, never of a permission string**, and never
 * of a `can:` middleware on the route. SegmentPolicy owns the mapping from an
 * ability to a permission, and a controller naming Permission::ViewSupporters
 * directly would be a second copy of it, free to drift from the first.
 *
 * **The policy answers exactly `viewAny`, `create`, `update` and `delete`, and
 * denies every other ability against a Segment silently** -- indistinguishably
 * from a considered refusal, measured at Phase 1 Step 2. This controller is the
 * first call site that policy has ever had, so it is the first place that could
 * cost anybody debugging time. It asks for none but those four, and a surface
 * that needs a fifth has to add the method *and* its allow test in the same
 * edit: `view` in particular is absent because this module ships no page for a
 * single segment.
 */
class SegmentController extends Controller
{
    /**
     * Laravel 11 emptied the base Controller, so authorize() is not inherited
     * from anywhere and $this->authorize() would be a fatal call to an
     * undefined method. The trait is applied here rather than to the base class
     * for the reason its two siblings give: raising it would hand every present
     * and future controller an authorization surface on behalf of the ones that
     * asked.
     */
    use AuthorizesRequests;

    /**
     * Show the narrowings this campaign has named.
     *
     * **Ordered by name, where both sibling lists are ordered by arrival, and
     * the difference is what a segment is for.** A supporter list and a blast
     * list answer "what has changed lately", so the newest row belongs at the
     * top. A segment is a thing a campaign *named* in order to recognise it
     * later and come back to it, so the order that helps is the one an operator
     * can predict from the name they are looking for.
     *
     * **No id tie-break, and its absence is a consequence of Step 1 rather than
     * an oversight.** The tie-break on the other two lists is not tidiness: an
     * order that is not total lets two rows sharing a `created_at` swap places
     * between requests, which under pagination shows one row twice and hides
     * another. `segments.name` carries a unique index, so ordering by it is
     * already total and there is nothing left for a tie-break to decide.
     *
     * **Unpaginated, and the trigger below names a quantity rather than a
     * screen.** Blueprint v0.28 records why that distinction matters: the blast
     * list was left unpaginated under the trigger "the first campaign whose list
     * needs more than one screen", which counts rows on the page, while its cost
     * lived in an aggregate over another table entirely. This page has no such
     * cost -- it is one select over one small table, with no subquery per row and
     * deliberately no audience count beside each segment (D-28) -- so here the
     * rows on the page genuinely are the quantity that grows. What could make it
     * grow fast is not an operator typing. **Trigger to revisit:** the first
     * thing that creates segments other than an operator naming one by hand,
     * which is the half of the blast list's original trigger that was measuring
     * the right quantity.
     *
     * **Whole models go to the browser, and that is safe today rather than
     * guaranteed.** `toArray()` returns every column, so anything added to
     * `segments` ships to the page without anybody choosing to send it -- which
     * is how a supporter's unsubscribe credential reached the list page's own
     * HTML at Phase 2 Step 5, and why `Supporter` carries a `$hidden`. Nothing
     * on `segments` is a secret: an id, an authorship id, a name a campaign
     * typed and a list of postcode prefixes, which D-24 kept free of any string
     * typed about a person. That is a property of today's columns and nothing
     * enforces it, so a column added here is a column the browser gets.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Segment::class);

        return Inertia::render('segments/Index', [
            'segments' => Segment::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Show the form for naming a new narrowing.
     */
    public function create(): Response
    {
        $this->authorize('create', Segment::class);

        return Inertia::render('segments/Create');
    }

    /**
     * Name a new narrowing of the campaign's list.
     */
    public function store(NameSegmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Segment::class);

        $segment = new Segment($request->named());

        // Authorship is stamped here rather than mass-assigned, and
        // `operator_id` is deliberately absent from the model's fillable list:
        // a form able to set it could put somebody else's name on a segment
        // other people's work will be aimed by. BlastController::store() is the
        // precedent, and the shape matters -- handing this key to create()
        // alongside the fillable ones drops it *silently*, because Laravel
        // guards every attribute by default and mass assignment does not
        // complain about the ones it refuses.
        //
        // This is the segment's own record of who named it, nulled when that
        // operator leaves the campaign. It is not the audit trail, which is
        // D-27's and is untouched here.
        $segment->operator_id = $request->user()?->getKey();

        $segment->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment saved.')]);

        return to_route('segments.index');
    }

    /**
     * Show the form for re-aiming a segment already named.
     *
     * Governed by `update` rather than by a `view` ability, which is
     * SupporterController::edit()'s decision for the same reason: this module
     * ships no read-only page for one segment, so the only reason to open one
     * is to change it, and adding `view` to the policy would create an ability
     * nothing checks. It matters because SegmentPolicy answers exactly the
     * abilities it has methods for and denies every other one silently -- so the
     * day a read-only page arrives, `view` and its allow test have to be added
     * in the same edit or the 403 will look deliberate.
     */
    public function edit(Segment $segment): Response
    {
        $this->authorize('update', $segment);

        return Inertia::render('segments/Edit', [
            'segment' => $segment,
        ]);
    }

    /**
     * Re-aim a segment already named.
     *
     * **What this does to a blast that used the segment is D-27's, and the
     * question now has a subject.** Step 4 gave a blast a pointer, so re-aiming
     * a segment re-aims every blast pointing at it -- correctly and by design
     * for a draft, because that is the whole value of pointing rather than
     * retyping. For a blast the campaign has already **committed** it is not
     * correct at all: `SendBlast` reads the rule when the job runs, so a send
     * queued against one narrowing can go out against another. Nothing here
     * refuses that yet. It is D-27's and Step 5's, and it is named rather than
     * quietly left as an unremarked gap.
     */
    public function update(NameSegmentRequest $request, Segment $segment): RedirectResponse
    {
        $this->authorize('update', $segment);

        $segment->update($request->named());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment updated.')]);

        return to_route('segments.index');
    }

    /**
     * Remove a segment from the campaign.
     *
     * **Not withheld from Staff, unlike removing a supporter, and that is D-25
     * rather than an oversight.** `DeleteSupporters` protects a person's
     * existence against a table with no soft delete; a segment holds a name and
     * a handful of postcodes that retype in seconds, destroys no supporter when
     * it goes, and is already emptiable by anybody holding `EditSupporters` --
     * who can strip its prefixes down to a rule matching nobody. So there is no
     * control on this module's pages that has to be hidden from anyone.
     *
     * **A segment a blast is aimed at cannot be removed, and this refusal is
     * forced by the schema rather than chosen here.** `blasts.segment_id`
     * restricts on delete, because the two alternatives are both wrong in ways
     * nothing would report: nulling the pointer would silently widen a blast
     * aimed at one ward to every supporter the campaign may contact, and
     * cascading would destroy the record of a message already in other people's
     * inboxes. So the database refuses, and this turns its refusal into a
     * sentence an operator can act on -- without it they would see a 500.
     *
     * **This is a sliver of D-27 and not the whole of it, and the boundary is
     * stated so Step 5 is not read as already done.** What is answered here is
     * only what a *deletion* does, because adding the foreign key forced a
     * choice about it. What an *edit* does to a blast the campaign has already
     * committed is untouched, and so is whether the trail records either.
     *
     * **The check is the message and the foreign key is the guarantee.** An
     * operator aiming a blast at this segment between the query below and the
     * delete still loses the race to the database, which refuses; that is a 500
     * rather than a wrong outcome, and nothing is destroyed. It is recorded
     * rather than defended against, because the alternative -- catching a bare
     * QueryException around the delete -- would swallow refusals that have
     * nothing to do with this one.
     */
    public function destroy(Segment $segment): RedirectResponse
    {
        $this->authorize('delete', $segment);

        $aimedHere = $segment->blasts()->count();

        if ($aimedHere > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans_choice(
                '{1} A blast is aimed at that segment, so it cannot be removed. Re-aim that blast first.'
                .'|[2,*] :count blasts are aimed at that segment, so it cannot be removed. Re-aim them first.',
                $aimedHere,
            )]);

            return to_route('segments.index');
        }

        $segment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment removed.')]);

        return to_route('segments.index');
    }
}
