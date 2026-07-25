<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Supporters\StoreSupporterRequest;
use App\Http\Requests\Supporters\UpdateSupporterRequest;
use App\Models\Supporter;
use App\Supporters\SupporterExport;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Supporter::class);

        return Inertia::render('supporters/Index', [
            'supporters' => Supporter::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
        ]);
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
     * The cost is that the whole list is read inside one request. Today that is
     * the same list the index action already renders whole, so a size that
     * breaks this breaks the page first; Step 6 owns both, and they should move
     * together.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('export', Supporter::class);

        return response()->streamDownload(
            function (): void {
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

                SupporterExport::writeTo($stream);

                fclose($stream);
            },
            SupporterExport::filename(),
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
     */
    public function destroy(Supporter $supporter): RedirectResponse
    {
        $this->authorize('delete', $supporter);

        $supporter->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supporter removed.')]);

        return to_route('supporters.index');
    }
}
