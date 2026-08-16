<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Districts\DistrictClaim;
use App\Districts\ZctaDistricts;
use App\Http\Requests\Supporters\StoreSupporterRequest;
use App\Http\Requests\Supporters\UpdateSupporterRequest;
use App\Models\Segment;
use App\Models\Supporter;
use App\Segments\SegmentNarrowing;
use App\Supporters\SupporterExport;
use App\Tenancy\CampaignSeat;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The campaign's supporter list, and the operator's work on it.
 *
 * Sits at the root of app/Http/Controllers/ rather than in a Supporters/
 * sub-directory: the Settings/ precedent exists because two controllers share
 * that concern, and one controller needs no directory to hold it. The module's
 * own vocabulary lives in app/Supporters/, where the framework reads nothing.
 *
 * **Authority is asked of the policy, never of a permission string.** The
 * mapping from an ability to a permission is precisely what SupporterPolicy
 * exists to own, and a controller naming Permission::EditSupporters directly
 * would be a second copy of that mapping, free to drift from the first. The
 * policy is reached by #[UsePolicy] on the model, so authorize() finds it
 * without any registration here.
 */
class SupporterController extends Controller
{
    /**
     * Laravel 11 emptied the base Controller, so authorize() is not inherited
     * from anywhere and $this->authorize() would be a fatal call to an
     * undefined method. The trait is applied here rather than to the base class
     * because one controller needs it: raising it to the base would hand
     * every present and future controller an authorization surface on behalf
     * of the single one that asked.
     */
    use AuthorizesRequests;

    /**
     * How many supporters one page of the list carries.
     *
     * **Chosen for the person reading it, because the database is indifferent.**
     * Measured on a campaign of 250,000 supporters, the first page costs 47.8 ms
     * at 25 per page and 57.4 ms at 250 — a 10 ms spread across a tenfold change
     * in size, because the work is dominated by locating the page rather than by
     * carrying it. So this is not a performance constant, and treating it as one
     * would be inventing a trade-off the measurement says does not exist.
     *
     * 50 is roughly two screens: enough that scanning for somebody usually ends
     * on the first page, few enough that the page stays quick to skim.
     */
    private const int PER_PAGE = 50;

    /**
     * Show the campaign's supporters, a page at a time.
     *
     * Order is by arrival, newest first, because `created_at` is the only
     * history this module keeps and it is the one column present on every row.
     * Sorting by family name was the obvious alternative and is the one thing
     * the schema cannot do honestly: a supporter whose source gave one name
     * string has no family name at all, so the list would order some rows and
     * strand the rest — a cost Step 1 accepted knowingly when it chose to
     * record name parts rather than fabricate them.
     *
     * **The id tie-break is what makes pagination correct, not merely tidy.**
     * An order that is not total lets two supporters carrying the same
     * `created_at` swap places between requests, and under a LIMIT/OFFSET that
     * is not a cosmetic wobble: a row that moves from the end of page one to the
     * start of page two is shown twice, and one moving the other way is never
     * shown at all. Rows sharing a timestamp are the common case rather than a
     * corner, since one import writes thousands inside a single transaction.
     *
     * **Unpaginated, this action did not survive a real list.** Measured by
     * seeding a campaign and timing it: 250,000 supporters cost 77.6 seconds,
     * 680 MB of memory and a 64.5 MB JSON payload, and 50,000 already cost
     * 15.5 seconds and 132 MB. The page is the first thing in this module to
     * break under size, and it breaks on memory rather than on time — which is
     * why Step 5 could record that a list breaking the export breaks the page
     * first, and why the export, whose memory is flat, is left streaming whole.
     *
     * **Narrowed by a segment when the request names one, and by nothing else.**
     * This is the first request input this action has ever read that changes
     * which rows come back — until now it read only `page`, which is why the
     * plan could describe it as reading no request input at all. The narrowing
     * is a stored segment rather than a postcode typed here: a segment is
     * already named, already validated and already the product's one definition
     * of what a prefix means, so the list gets the rule the blast module gets
     * rather than a second one typed into a box. A segment may also name a
     * congressional district (D-37), which the blast module cannot yet aim at;
     * App\Segments\SegmentNarrowing is where the list and its export turn
     * either kind into a query, so the two cannot come to answer differently.
     *
     * **`withQueryString()` was already here and is what makes paging correct.**
     * It re-appends the request's query to every page link, so `?segment=` rides
     * from page one to page two without being wired — and a filter that silently
     * dropped on page two would show an operator a different list from the one
     * they asked for, which is a defect visible only on the second page. It was
     * here before this step; what changed is that it now carries something.
     *
     * **Subscription status is deliberately not filtered.** The narrowed list
     * shows everyone the segment names, including people who unsubscribed,
     * because an operator correcting a record has to be able to find them. That
     * is the opposite guarantee to the one a blast makes from the same rule, and
     * it is why App\Supporters\PostcodeNarrowing and
     * App\Districts\DistrictNarrowing carry no status condition of their own
     * (D-24).
     *
     * The campaign's segments are handed to the page as well, because the
     * control that aims the list has to list them. They are read through
     * SegmentPolicy rather than assumed readable by anybody who reached this
     * action — the two abilities agree today, both answering from
     * ViewSupporters, and asking is what keeps them from drifting apart in
     * silence.
     *
     * **Every segment, unpaginated, on the one page in this application that is
     * paginated at all — and Step 6 measured what that will cost.** This action
     * bounds the supporters it sends at fifty and bounds the segments at
     * nothing, so the two halves of what it returns grow under different
     * rules. At a thousand segments the prop is 163,894 B against
     * roughly 18,600 B for the fifty supporters and the rest of the page: nine
     * times more segment data than supporter data, here, where reading the
     * whole list cost 680 MB and is what forced paging in the first place. It
     * is one query at every size, so the cost is the serialising rather than
     * the reading. **Nothing is built for it, and the trigger is not this
     * page's own** — it is SegmentController::index()'s, the first thing that
     * creates segments other than an operator naming one by hand, recorded
     * there and governing this page too.
     *
     * **Each row on the page carries what the product may say about its
     * supporter's district, worked out here on every request and stored
     * nowhere (D-35).** The district data ships with the code (D-34), so it
     * changes only when a release does. A district column on `supporters` would
     * be a cache that goes wrong at exactly that deploy, which no test run can
     * see, and it would need a job to fill it -- and with no queue worker
     * running anywhere, every supporter's district would stay empty while the
     * page looked finished. Working it out costs one read of the relation per
     * request: on a full page of fifty, measured over three runs of 25, the
     * request went from a median of 27.6–28.0 ms to 44.0–44.3 ms, of which the
     * read is about 14 ms. Only the rows on the page are answered, so the cost
     * does not grow with the list.
     *
     * **The Congress and the publication date travel with the answers, and
     * are formatted here** rather than on the page, so the page cannot shift a
     * date across a timezone or show an answer without the map it was read
     * against: every district named on that page is named as that Congress drew
     * it (D-43).
     *
     * **When the campaign has recorded the seat it is running for, each answer
     * also says where the supporter stands against it (D-40)** -- in it, maybe
     * in it, or not in it -- decided by DistrictClaim, so the page is given
     * nothing to decide. The seat is read from the campaign's registry row,
     * which costs no query.
     *
     * **A list narrowed to a district segment says what the narrowing leaves
     * out** -- how many ZIP codes lie wholly inside the district and how many
     * cross its boundary, whose supporters are not shown -- because D-32's rule
     * leaves out more of a dense district than it keeps (MA-07 holds 17 ZIP
     * codes whole and is crossed by 26 more), and a list headed with the
     * district's name would otherwise read as everyone in it. The relation is
     * read once for the narrowing, the district column and that sentence; the
     * district's claimable ZIP codes are worked out twice, once for the
     * narrowing and once for the sentence. Measured on sixty supporters over
     * three runs of 25 requests, a list narrowed to MA-07 took a median of
     * 60.3-61.6 ms against 45.0-46.2 ms unnarrowed and 36.4-36.9 ms narrowed by
     * a ZIP code prefix.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supporter::class);
        $this->authorize('viewAny', Segment::class);

        $segment = $this->narrowingSegment($request);
        $relation = ZctaDistricts::shipped();

        $supporters = Supporter::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($segment instanceof Segment) {
            $supporters = SegmentNarrowing::apply($supporters, $segment, $relation);
        }

        $page = $supporters->paginate(self::PER_PAGE)->withQueryString();
        $seat = CampaignSeat::current($relation);

        return Inertia::render('supporters/Index', [
            'supporters' => $page,
            'segments' => Segment::query()->orderBy('name')->get(),
            'narrowedTo' => $segment?->getKey(),
            'districts' => [
                'congress' => Number::ordinal($relation->congress()),
                'publishedOn' => Carbon::parse($relation->publishedOn())->isoFormat('MMMM D, YYYY'),
                'seat' => $seat?->label(),
                'narrowing' => $segment instanceof Segment ? self::districtNarrowing($segment, $relation) : null,
                'bySupporter' => $page->getCollection()->mapWithKeys(
                    fn (Supporter $supporter): array => [
                        $supporter->getKey() => DistrictClaim::for($supporter->postcode, $relation, $seat),
                    ],
                ),
            ],
        ]);
    }

    /**
     * What a district segment's narrowing keeps and leaves out, for the page to
     * say -- or null for a segment of ZIP code prefixes, which leaves nothing
     * out that its own rule did not name.
     *
     * `seat` is null when the relation does not name the stored district, and
     * the page then says the segment reaches nobody, which is what
     * SegmentNarrowing does with it.
     *
     * @return array{district: string, seat: string|null, wholly: int, crossing: int}|null
     */
    private static function districtNarrowing(Segment $segment, ZctaDistricts $relation): ?array
    {
        if ($segment->district === null) {
            return null;
        }

        $seat = $segment->seat($relation);

        if ($seat === null) {
            return ['district' => $segment->district, 'seat' => null, 'wholly' => 0, 'crossing' => 0];
        }

        $wholly = count(DistrictClaim::claimableIn($seat, $relation));

        return [
            'district' => $segment->district,
            'seat' => $seat->label(),
            'wholly' => $wholly,
            'crossing' => count($relation->zctasTouching($seat->geoid)) - $wholly,
        ];
    }

    /**
     * The segment this request asks the list to be narrowed to, if any.
     *
     * **Every way of failing to identify a segment answers 404, and never "no
     * narrowing", because the two are one predicate apart and only one of them
     * is safe.** A request naming a segment that does not exist is an operator
     * following a stale link or mistyping a URL; handing them the whole list
     * under a heading that says it is narrowed is the same widening the `%`
     * metacharacter would have produced, arriving through the router instead of
     * through SQL. The direction of harm here is milder than a blast's — a
     * widened list is looked at, a widened send is received — but it is the same
     * rule, and relaxing it for the milder case is how it stops being one.
     *
     * **A malformed id is refused before it reaches a query, not after.**
     * `segments.id` is a bigint, so comparing it against `abc` raises SQLSTATE
     * 22P02 rather than matching no rows: a 500 for anybody who mistypes a link,
     * with the offending value inlined into the exception message. That is the
     * trap `whereUuid` closes on the unsubscribe routes, and the answer is the
     * same — refuse before a statement is built.
     */
    private function narrowingSegment(Request $request): ?Segment
    {
        $named = $request->query('segment');

        if ($named === null || $named === '') {
            return null;
        }

        // query() answers with a string or an array -- never an int -- so an
        // array (`?segment[]=1`) falls straight through to the refusal below
        // rather than reaching filter_var, which would answer null for it.
        $id = is_string($named) ? filter_var($named, FILTER_VALIDATE_INT) : false;

        abort_if($id === false, 404);

        return Segment::query()->findOrFail($id);
    }

    /**
     * Show the form for adding a supporter by hand.
     */
    public function create(): Response
    {
        $this->authorize('create', Supporter::class);

        return Inertia::render('supporters/Create');
    }

    /**
     * Add a supporter to the campaign's list.
     */
    public function store(StoreSupporterRequest $request): RedirectResponse
    {
        $this->authorize('create', Supporter::class);

        Supporter::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supporter added.')]);

        return to_route('supporters.index');
    }

    /**
     * Show the form for correcting a supporter already on the list.
     *
     * Governed by `update` rather than by a `view` ability, and that is a
     * decision rather than an oversight. This module ships no read-only page
     * for one supporter -- the only reason to open one is to change them -- so
     * adding `view` to the policy would create an ability nothing checks. It
     * matters because the policy answers exactly the abilities it has methods
     * for and denies every other one silently, indistinguishably from a
     * considered refusal: the day a read-only page arrives, `view` and its
     * allow test have to be added in the same edit or the 403 will look
     * deliberate.
     */
    public function edit(Supporter $supporter): Response
    {
        $this->authorize('update', $supporter);

        return Inertia::render('supporters/Edit', [
            'supporter' => $supporter,
        ]);
    }

    /**
     * Correct a supporter already on the list.
     */
    public function update(UpdateSupporterRequest $request, Supporter $supporter): RedirectResponse
    {
        $this->authorize('update', $supporter);

        $supporter->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supporter updated.')]);

        return to_route('supporters.index');
    }

    /**
     * Send the campaign's whole list back to the operator as a file.
     *
     * **The one action on this controller that returns something other than an
     * Inertia page, and it cannot be one.** An Inertia visit is an XHR that
     * expects a JSON page object; a file has to arrive as an ordinary
     * navigation, which is why the control for this on the list page is a plain
     * anchor rather than a <Link>.
     *
     * **Streamed, not queued, and nothing is written to disk.** The rows go
     * into the response as they are read from the database. The alternative --
     * a job that writes a file somewhere and hands back a link -- would put a
     * complete second copy of the campaign's list on the campaign's own disk,
     * where it would need a retention window of its own, a download route of
     * its own, and authorization on that route; and until it was collected it
     * would be a copy of every supporter sitting in a directory that no
     * deletion path reaches. That is the problem this step was opened to bound,
     * so producing another instance of it to solve half of it would be a poor
     * trade.
     *
     * The cost is that the whole list is read inside one request. **This
     * paragraph used to add that the index action renders the same list whole,
     * which was true when it was written and stopped being true one step
     * later** — that action has paginated at fifty since Phase 1 Step 6, so
     * "the same list" named two different things for two phases.
     *
     * What survives the correction is the ordering, and it survives for a
     * sharper reason than the one it was given. The page breaks first, and the
     * two break in different currencies: the page degrades in memory, 680 MB
     * and a 64.5 MB payload at 250,000 supporters, while SupporterExport chunks
     * and holds flat memory at any size, degrading in wall-clock alone. They
     * were answered in the same step and took two different answers, which is
     * why the page pages and this still streams whole. Its own trigger is
     * recorded on SupporterExport, and it is a request timeout rather than a
     * row count.
     *
     * **The file follows the narrowing, and the control on the page says so.**
     * An operator who has narrowed the list to 300 people and then exports has
     * asked for those 300; a file of 12,000 would be a surprise they discover
     * after opening it rather than a list they were shown. The alternative was
     * to export the whole list regardless and say *that* on the page, which is
     * defensible and was not chosen: of the two, only this one keeps the file
     * and the screen answering the same question.
     *
     * `export` is Owner-only where the list is not, so the two surfaces do not
     * have the same audience -- a Staff operator narrows the list and is offered
     * no export at all. That is unchanged by this and is why the segment
     * reaches this action the same way it reaches the page, through the query
     * string, rather than through anything remembered between requests.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Supporter::class);
        $this->authorize('viewAny', Segment::class);

        $segment = $this->narrowingSegment($request);

        return response()->streamDownload(
            function () use ($segment): void {
                $stream = fopen('php://output', 'w');

                if ($stream === false) {
                    // Refused rather than skipped, and the reason is that the
                    // failure is otherwise indistinguishable from a true
                    // answer: writing nowhere produces a file with no rows,
                    // which an operator reads as "this campaign has nobody on
                    // its list". The same trap SupporterFile::open() names from
                    // the reading side.
                    //
                    // Stated for the next reader: this cannot be driven red by
                    // a test, because php://output does not fail to open in any
                    // environment this runs in. It is a refusal to continue on
                    // an impossible value rather than a guard, and it is not
                    // counted as one.
                    throw new RuntimeException('The export could not be opened for writing.');
                }

                SupporterExport::writeTo($stream, $segment);

                fclose($stream);
            },
            SupporterExport::filename($segment),
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * Remove a supporter from the campaign permanently.
     *
     * One of the two abilities the roles disagree about -- export() above is
     * the other, and between them they are the only controls on this module's
     * pages that have to be hidden from somebody. There is no soft delete here
     * and nothing to restore from, which is exactly why it is withheld
     * from Staff: the ordinary way to stop contacting somebody is to
     * unsubscribe them, a status kept precisely so a later import cannot put
     * them back.
     *
     * **What this one statement reaches, measured at Phase 3 Step 6 rather
     * than reasoned about.** It is the whole of the platform's erasure path.
     * It removes the supporter row; `blast_recipients` keeps its row with
     * `supporter_id` nulled and `sent_at` intact, which is Phase 2 Step 4's
     * answer to this same question asked of that table; and `segments` and
     * `blasts.committed_prefixes` are untouched, because neither holds anything
     * about a person. Both store postcode prefixes, so a segment that named the
     * erased supporter's own household simply reaches nobody afterwards — which
     * was run rather than asserted. The `segments` migration carries the
     * measurement and the one residual it leaves standing.
     *
     * **`blasts.committed_zip_codes` (D-38) joins that list rather than
     * lengthening this path.** Its values are a district's claimable ZIP codes
     * as read from the relation that ships with the code, never a value copied
     * from a supporter's row and never a string an operator typed about a
     * person. Measured at Phase 4 Step 7 by running a deletion after a district
     * send: afterwards neither the supporter's address nor their unsubscribe
     * token is in any column of any campaign table, the frozen ZIP codes are
     * unchanged, and the recipient row keeps `sent_at` with its key nulled.
     */
    public function destroy(Supporter $supporter): RedirectResponse
    {
        $this->authorize('delete', $supporter);

        $supporter->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supporter removed.')]);

        return to_route('supporters.index');
    }
}
