<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blasts\BlastAudience;
use App\Blasts\BlastStatus;
use App\Blasts\SendBlast;
use App\Http\Requests\Blasts\ComposeBlastRequest;
use App\Models\Blast;
use App\Models\Segment;
use App\Tenancy\CampaignContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
     * blasts has been running for years.
     *
     * **The trigger this docblock used to record was the wrong one, and Step 6
     * measured that rather than reasoning about it.** It said "the first
     * campaign whose blast list needs more than one screen", which counts the
     * rows on the page. The cost is not in the rows on the page: it is in the
     * counts below, and each of those reads one blast's whole recipient set. On
     * a seeded campaign of 250,000 supporters this query costs 139 ms at one
     * blast, 449 ms at three and 1,146 ms at ten -- ten rows, well inside one
     * screen, at over a second. So the recorded trigger could never fire before
     * the cost arrived, which is the failure mode of a trigger written from the
     * shape of the page rather than from what the page runs.
     *
     * **And pagination is not the answer, which is the more useful half.**
     * Bounding a page at fifty blasts still runs fifty of these subplans, each
     * scanning that blast's own recipients -- roughly 117 ms apiece on the
     * measurement above, so a full page would be slower than the whole
     * unpaginated list is today. Paging fixes a page that carries too much;
     * this page carries almost nothing and *computes* too much. The structural
     * answer is to stop counting -- a reached and a refused column on `blasts`,
     * maintained by the sending path, turning the page into one row read -- and
     * it is deliberately not built, because no campaign on this platform is
     * within two orders of magnitude of needing it (Blueprint §3).
     *
     * **Trigger to revisit, replacing the one above:** the first campaign whose
     * `blast_recipients` table passes roughly a million rows, which is where
     * this page crosses half a second; or the first thing that creates blasts
     * other than an operator typing one, which is the half of the old trigger
     * that was measuring the right quantity and is kept.
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
                // **The narrowing each blast points at, as one eager load
                // rather than a query per row**, which is the same distinction
                // the counts below turn on. This is one select over `segments`
                // for the whole page -- a table holding as many rows as a
                // campaign has named narrowings -- where the audience count
                // this page still refuses would be a fresh BlastAudience query
                // per blast against every supporter.
                //
                // It is loaded because a page that cannot say what a blast was
                // aimed at is a regression on a surface Phase 2 built
                // deliberately, and `segment_id` alone is an id: it names
                // nothing an operator can read. **A whole Segment per blast
                // rather than the name alone**, matching every other list this
                // application hands to Inertia; nothing on `segments` is a
                // secret, which SegmentController::index() states and which is
                // a property of today's columns rather than a guarantee.
                ->with('segment')
                // **What a send has actually done, as two aggregates rather
                // than a query per row.** This is the counterpart to the
                // trigger recorded against putting an *audience* count here:
                // that would be one BlastAudience query per blast, while these
                // are counts over `blast_recipients`' own index. They are also
                // the only honest answer the application can give about a
                // queued blast -- no worker runs anywhere, so a campaign that
                // cannot tell "queued" from "sent" would believe it had
                // contacted its supporters when it had not.
                //
                // **"Over the index" is true and was doing less work than it
                // reads as**, which Step 6 measured rather than assumed. The
                // unique index on (blast_id, supporter_id) locates a blast's
                // rows, but neither `sent_at` nor `failure_reason` is in it, so
                // each count is a Bitmap Heap Scan over every one of that
                // blast's recipients: 23,491 heap blocks apiece, per blast, per
                // page load, and the refused count throws away 248,750 of the
                // 250,000 rows it just read to arrive at 1,250. Two
                // partial indexes take the same query from 1,146 ms to 335 ms
                // for 16.6 MB, and are still linear in the recipient count;
                // they are not built for that reason, and because nothing is
                // near the size that would justify them.
                //
                // **The column order of that unique index is load-bearing in
                // two directions, so it is worth saying before somebody tidies
                // it.** Built the other way round it would enforce exactly the
                // same uniqueness, which is what makes the swap look free.
                // Measured on the same seeded campaign, with the index rebuilt
                // as (supporter_id, blast_id) and nothing else changed: this
                // query stops using it at all and falls to a Seq Scan on
                // `blast_recipients`, 1,288 ms to 3,716 ms. The erasure lookup
                // moves the other way, from 11 index searches to 1 and from
                // 0.108 ms to 0.065 ms, because it finds its rows by
                // `supporter_id` and that column has no index of its own.
                //
                // Erasure is cheap either way and needs no help: at 2.5M
                // recipient rows PostgreSQL reaches it through the shipped
                // index as a *non-leading* column -- one index search per
                // distinct blast -- and deletes a supporter in 2.2 ms. So the
                // order is chosen for the counts, which are the half that
                // cannot recover from losing it.
                //
                // **Re-measure this with the correlated query above, never with
                // a standalone count**, which is the mistake made while
                // establishing it: `select count(*) ... where blast_id = ?`
                // against a table holding three blasts is a third of the rows,
                // where the planner correctly prefers a sequential scan in
                // *both* orders and reports the two as identical.
                ->withCount([
                    'recipients as reached_count' => fn (Builder $query) => $query->whereNotNull('sent_at'),
                    'recipients as failed_count' => fn (Builder $query) => $query->whereNotNull('failure_reason'),
                ])
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

        return Inertia::render('blasts/Create', [
            'segments' => $this->segments(),
        ]);
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

            // The narrowings this campaign has named, so the operator can point
            // at one rather than retype it. See segments() for why an operator
            // who may not read them is still given this page.
            'segments' => $this->segments(),

            // **A prediction, not a promise, and the page says so in those
            // words.** The audience is a rule evaluated again when sending
            // starts (D-14), so somebody who unsubscribes between now and then
            // is correctly left out and this number moves. Materializing a list
            // here would make the number exact and the send wrong, which is the
            // trade D-14 refused.
            'audienceSize' => BlastAudience::size($blast),

            // **Whether a supporter could answer this message, said before it
            // goes rather than discovered afterwards.** A campaign with no
            // contact address sends with no reply path at all -- deliberately,
            // because falling back to the platform's address would route a
            // supporter's answer to people who cannot act on it. That is a
            // legitimate state and an easy one to be in by accident, so the one
            // page offering to send says which it is.
            'replyTo' => CampaignContact::address(),
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
     * Commit a blast to sending, and queue the work that does it.
     *
     * **The irreversible act of this whole module, and the transition is what
     * makes it safe rather than the dispatch.** The status is moved out of
     * draft by an update that names `draft` in its own `where`, so the database
     * decides whether this request is the one that committed the blast: a
     * second request arriving at the same instant updates zero rows and is
     * turned away, with no read-then-write window for it to slip through. Only
     * the request that won dispatches anything.
     *
     * That is a stronger guarantee than a queue lock, and it is deliberately
     * not the only one. `SendBlast` carries a campaign-scoped
     * WithoutOverlapping so a second *worker* cannot run the same send
     * concurrently, and `blast_recipients` carries a unique index so that if one
     * ever does, no supporter can be written to twice. Three mechanisms for one
     * property, because it is the property no later commit repairs.
     *
     * A blast reaching nobody is refused rather than committed. The count is a
     * prediction and this check can go stale in the moment after it runs -- but
     * committing is one-way, so spending a blast on an aim that currently names
     * nobody is a mistake the operator cannot undo, and an aim that has simply
     * gone empty by the time the worker starts is a send of nothing rather than
     * a blast that can never be edited again.
     *
     * **This is also where a segment-aimed blast's rule stops moving (D-27).**
     * Until now a blast pointed at a segment for its whole life, so editing the
     * segment changed what an already-committed send would reach -- measured,
     * and not only in the window before the worker starts: `SendBlast` re-reads
     * the rule on every attempt, and `retry_after` releases any real send back
     * to the queue, so a segment edited mid-send admitted a supporter who was
     * never in the committed audience. The rule is therefore copied onto the
     * blast by the same statement that commits it. A draft keeps pointing, which
     * is the whole value of pointing; what the campaign gives up the right to
     * change, it also stops being able to have changed for it.
     *
     * The copy is written here before anything reads it, which is this project's
     * usual order -- a column and its writer are pointless apart, and the reader
     * that prefers it is one commit away.
     */
    public function send(Request $request, Blast $blast): RedirectResponse
    {
        $this->authorize('send', $blast);

        if ($blast->status->isCommitted()) {
            return $this->refuseCommitted($blast) ?? to_route('blasts.index');
        }

        if (BlastAudience::size($blast) === 0) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('That blast currently reaches nobody, so it has not been sent. Change who it is aimed at and try again.'),
            ]);

            return to_route('blasts.edit', $blast);
        }

        // **The aim is read here, one statement before it is frozen (D-27).**
        // Read through BlastAudience rather than off the segment, because the
        // rule the send will walk and the rule frozen here have to be the same
        // rule -- a second reading of a segment in this file would be the copy
        // D-29 exists to prevent, and the two disagreeing is a message going
        // somewhere the campaign did not commit it to.
        $committedAim = BlastAudience::committedAimFor($blast);

        $committed = Blast::query()
            ->whereKey($blast->getKey())
            ->where('status', BlastStatus::Draft)
            ->update([
                // Written outside mass assignment, and these four columns are
                // absent from the model's #[Fillable] for exactly this reason:
                // a form able to set them could mark a message sent that never
                // went, walk a committed blast back to draft, or re-aim a
                // message that has already gone out.
                'status' => BlastStatus::Queued,
                'queued_at' => now(),

                // **The freeze, and it is in this statement rather than beside
                // it deliberately (D-27).** This update is already the thing
                // that decides whether *this* request is the one that committed
                // the blast -- the `where` on draft means a second request
                // updates zero rows. Putting the frozen aim in the same
                // statement makes it impossible for a blast to be committed
                // without one, so the property holds by construction rather
                // than by every future writer remembering. A separate write
                // afterwards would leave a window in which a committed blast
                // had no frozen rule, and the check constraint would refuse it
                // -- which is the database saying the same thing.
                //
                // Encoded rather than handed over as an array, because this is
                // the query builder rather than the model: Eloquent's casts run
                // on an attribute assigned to an instance, and nothing casts a
                // value passed to update(). The column is json and the cast on
                // the model reads it back as a list.
                'committed_prefixes' => $committedAim === null
                    ? null
                    : json_encode($committedAim, JSON_THROW_ON_ERROR),

                // Who committed it, which is not who wrote it: Staff may draft a
                // blast and only an Owner may send one, so `operator_id` answers
                // a different question (D-17).
                'queued_by' => $request->user()?->getKey(),
                'updated_at' => now(),
            ]);

        if ($committed === 0) {
            // Another request committed it between the check above and here.
            // Reported as the same refusal, because from the operator's side it
            // is the same fact: the blast is no longer theirs to send.
            return $this->refuseCommitted($blast->refresh()) ?? to_route('blasts.index');
        }

        SendBlast::dispatch($blast->refresh(), (string) tenant('id'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('That blast is queued for sending.'),
        ]);

        return to_route('blasts.index');
    }

    /**
     * The narrowings this campaign has named, in the order its own list shows
     * them, or none if this operator may not read them.
     *
     * **Asked rather than authorized, and the difference from
     * SupporterController::index() is deliberate rather than an
     * inconsistency.** There, `viewAny` on a Segment is a hard authorize and
     * costs nothing: an operator already needed ViewSupporters to reach the
     * supporter list at all, so the second check can only agree with the first.
     * Here the operator's authority to be on this page comes from EditBlasts,
     * which is a different permission -- so refusing the whole page on a second
     * one would withdraw a capability they plainly hold. Aiming a blast by
     * postcode does not require reading a segment, and it must not stop working
     * because they cannot.
     *
     * So the page renders and the select is simply not offered, which is what
     * it already does for a campaign that has named no segments. The two
     * authorities agree today -- `viewAny` answers from ViewSupporters and both
     * roles hold it -- so nothing an operator can currently see depends on this
     * branch. What it does is stop a page that lists a campaign's segments
     * answering to nothing that governs them.
     *
     * The order matches SegmentController::index() and the blast list's own
     * eager load, because an operator choosing from this select has just been
     * reading that list and a second order would make the same segments look
     * like different ones. Whole models rather than a projection, matching
     * every other list this application hands to Inertia -- nothing on
     * `segments` is a secret, which is a property of today's columns and is
     * stated in that controller rather than restated here.
     *
     * **Unbounded, on both pages that call this, and this is the arm of the
     * fan-out that paging could not fix.** Step 6 measured this same query on
     * all four surfaces that run it and found one query at every size with a
     * byte-identical payload -- 1,622 B at ten segments, 163,894 B at a
     * thousand. On the segment list the remedy would be paging; here the
     * segments are `<option>`s an operator chooses from, and half a dropdown is
     * a control that silently cannot reach some of the campaign's own
     * narrowings. So the trigger recorded in SegmentController::index() governs
     * this call too, and what it asks here is a different question from paging.
     *
     * @return Collection<int, Segment>
     */
    private function segments(): Collection
    {
        if (Gate::denies('viewAny', Segment::class)) {
            return new Collection;
        }

        return Segment::query()->orderBy('name')->get();
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
