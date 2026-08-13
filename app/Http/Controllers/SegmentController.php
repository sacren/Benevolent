<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Segments\NameSegmentRequest;
use App\Models\Blast;
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
     * cost -- it is one select over one small table, with no subquery per row
     * and deliberately no audience count beside each segment, which D-28
     * resolves below -- so here the rows on the page genuinely are the quantity
     * that grows. What could make it grow fast is not an operator typing.
     * **Trigger to revisit:** the first thing that creates segments other than
     * an operator naming one by hand, which is the half of the blast list's
     * original trigger that was measuring the right quantity.
     *
     * **Step 6 re-checked that trigger on all three of the things a trigger can
     * get wrong, and it failed on a fourth nobody had named.** Its *condition*
     * has not arrived: store() below is the only writer of a segment outside
     * the test suite, and no seeder makes one. Its *premise* is true and is now
     * measured rather than reasoned about -- this action runs exactly **one
     * query at every size**, from ten segments to a thousand, so unlike the
     * blast list this page's cost really is its rows. Its *quantity* is right,
     * because what grows is segments and segments are what it counts.
     *
     * **What is wrong is the surface, and that is v0.28's failure one axis
     * over: not the wrong noun, the wrong page.**
     * `Segment::query()->orderBy('name')->get()` -- the whole table,
     * unpaginated -- runs on four pages rather than one: here,
     * SupporterController::index(), and BlastController's create and edit
     * through its segments(). The prop is byte-identical on all four -- 1,622 B
     * at ten segments, 16,293 B at a hundred, 163,894 B at a thousand -- and
     * across those sizes this page costs 12.6 ms, 42.3 ms and 328.4 ms while
     * the supporter list costs 31.2 ms, 61.6 ms and 357.5 ms, both on one query
     * for the segments however many there are. At a thousand, the *supporter*
     * list ships 163,894 B of segments against roughly 18,600 B of everything
     * else: nine times more segment data than supporter data, on the page
     * Phase 1 Step 6 paginated precisely to bound its payload.
     *
     * **So the trigger is recorded on the one surface where its own remedy
     * would work.** Paging helps a list; on the other three the segments arrive
     * as `<option>`s in a select, and half a dropdown is not a smaller dropdown
     * but a control that silently cannot reach some of the campaign's own
     * narrowings. **The trigger above therefore governs all four surfaces, and
     * when it fires the first question is what the select does rather than what
     * this table does.** Nothing is built for it: a thousand segments is not
     * reachable by an operator typing, which is the condition itself.
     *
     * **D-28 resolved: no size beside a segment, in none of its three shapes,
     * and what decides it is not the cost.** Measured against a throwaway
     * campaign of 250,000 supporters, one count per segment -- the shape a
     * `@foreach` produces -- costs 270.4 ms at one row, 825.9 ms at three and
     * 2,752.5 ms at ten, where this page as it stands costs 1.4 ms. The single
     * statement anybody would reach for instead, one correlated subquery per
     * row, is **worse at every size**: 739.1 ms, 1,039.8 ms and 3,165.3 ms,
     * because an `exists` over `jsonb_array_elements_text` costs more per
     * supporter row than a plain folded comparison. Ten segments would already
     * be dearer than the blast list at ten blasts, which Phase 2 Step 6 called
     * "already over a second". That is v0.28's finding reached by a second
     * mechanism: there pagination was slower than none, here the tidy query is
     * slower than the untidy one.
     *
     * **No index closes it, and that is structural rather than a tuning gap.**
     * With an expression index on `left(replace(lower(postcode), ' ', ''), 3)`,
     * a length-3 prefix takes a Bitmap Heap Scan at 20.0 ms and a length-4
     * prefix takes a parallel sequential scan at 103.2 ms -- the index is
     * simply not used. D-24 admits prefixes of any length and
     * App\Supporters\PostcodeNarrowing binds `mb_strlen($prefix)` per prefix,
     * so no fixed set of indexes serves the product. Those two lines are the
     * answer to "add an index", rather than an argument.
     *
     * **The deciding reason is that one number here cannot mean both things,
     * and unlike a cost it does not expire.** Over the same 250,000
     * supporters, one segment narrows the *supporter list* to 250,000 while a
     * *blast* aimed at it reaches 187,615 -- a gap of 62,385, exactly the
     * seeded 25% unsubscribed rate. Step 3 established that the list may
     * legitimately show somebody who unsubscribed and that hiding them would be
     * wrong; App\Blasts\BlastAudience enforces subscribed-only by its shape
     * rather than by a parameter. So whichever of the two a size were computed
     * for, it would misinform the other reader by the whole of the campaign's
     * unsubscribed rate. Hardware improves; that does not.
     *
     * **The third shape -- a stored count something refreshes -- is refused
     * against Finding B by name rather than merely left out.** What would
     * refresh it is a scheduled task, and no scheduler runs anywhere:
     * `schedule:list` already reports four declarations, one of them the
     * retention D-10's stated justification depends on and which therefore
     * never happens. A fifth would be a fifth unguarded claim on infrastructure
     * that does not exist, and a count refreshed by nothing is worse than no
     * count, because it reads as current.
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
     * **What this does to a blast that used the segment is D-27's, and it is
     * answered rather than named now.** Step 4 gave a blast a pointer, so
     * re-aiming a segment re-aims every blast pointing at it -- correctly and by
     * design for a draft, because that is the whole value of pointing rather
     * than retyping. For a blast the campaign has already **committed** that
     * was not correct at all, and it was measured rather than feared: the send
     * resolves the rule on every attempt, so a segment edited after the commit
     * put the message in front of people who were never in the committed
     * audience.
     *
     * **This method is deliberately not where that was closed.** Refusing the
     * edit here was the obvious remedy and is the wrong one: it would lock a
     * segment for as long as any blast aimed at it stayed unsent, which with no
     * worker deployed is forever, and it would need a check-then-write window
     * that nothing backstops -- where the deletion refusal below at least has a
     * foreign key behind it. So an edit stays unconditionally allowed, which is
     * what a named narrowing is *for*, and the blast freezes what it was aimed
     * at when the campaign committed it. See `BlastController::send()`.
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
     * aimed at one precinct to every supporter the campaign may contact, and
     * cascading would destroy the record of a message already in other people's
     * inboxes. So the database refuses, and this turns its refusal into a
     * sentence an operator can act on -- without it they would see a 500.
     *
     * **This answers what a *deletion* does, and only that.** It was forced
     * into Step 4 because adding the foreign key could not avoid deciding it.
     * What an *edit* does to an already-committed blast is answered elsewhere
     * and deliberately not here -- the blast freezes its aim at the moment it
     * is committed, so an edit needs no refusal to be safe, while a deletion
     * still does because it would take the record itself away.
     *
     * **The check is the message and the foreign key is the guarantee.** An
     * operator aiming a blast at this segment between the query below and the
     * delete still loses the race to the database, which refuses; that is a 500
     * rather than a wrong outcome, and nothing is destroyed. It is recorded
     * rather than defended against, because the alternative -- catching a bare
     * QueryException around the delete -- would swallow refusals that have
     * nothing to do with this one.
     *
     * **Two messages, because one of them was advice nobody could take.** This
     * refusal used to say "Re-aim that blast first" whatever was aimed here,
     * and BlastController::refuseCommitted() makes that impossible for a blast
     * the campaign has committed -- so an operator holding a segment that a
     * sent message names was told to perform an act the application refuses,
     * and SegmentManagementTest proves that case is permanent rather than
     * transient. Step 6 split it. Where every blast aimed here is still a
     * draft the advice is real and is kept word for word. Where any is
     * committed, no re-aiming frees the segment and repeating the advice would
     * be a second unfollowable instruction, so the message says what is true
     * instead: the segment stays, because it is the record of what an
     * already-committed message was aimed at.
     *
     * **The second message reports the committed count and not the total.** A
     * segment held by one committed blast and three drafts is held by exactly
     * one thing the operator cannot move, and naming four would send them off
     * to re-aim three blasts that were never the obstacle.
     *
     * **Only `id` and `status` are read.** A whole Blast per row would ship
     * each one's `body` into a request that renders none of it -- the standing
     * residual on the blast list -- and none of it is needed to tell two states
     * apart.
     */
    public function destroy(Segment $segment): RedirectResponse
    {
        $this->authorize('delete', $segment);

        $aimedHere = $segment->blasts()->get(['id', 'status']);

        $committedHere = $aimedHere
            ->filter(static fn (Blast $blast): bool => $blast->status->isCommitted())
            ->count();

        if ($committedHere > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans_choice(
                '{1} A blast the campaign has committed is aimed at that segment, so it stays: it is the record of what that message was aimed at.'
                .'|[2,*] :count blasts the campaign has committed are aimed at that segment, so it stays: it is the record of what those messages were aimed at.',
                $committedHere,
            )]);

            return to_route('segments.index');
        }

        if ($aimedHere->isNotEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans_choice(
                '{1} A blast is aimed at that segment, so it cannot be removed. Re-aim that blast first.'
                .'|[2,*] :count blasts are aimed at that segment, so it cannot be removed. Re-aim them first.',
                $aimedHere->count(),
            )]);

            return to_route('segments.index');
        }

        $segment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment removed.')]);

        return to_route('segments.index');
    }
}
