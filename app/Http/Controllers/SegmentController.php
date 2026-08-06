<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Segment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
 * that needs one it does not answer has to add the method *and* its allow test
 * in the same edit: `view` in particular is absent because this module ships no
 * page for a single segment.
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
}
