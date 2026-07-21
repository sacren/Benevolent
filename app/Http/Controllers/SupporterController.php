<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supporter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;
use Inertia\Response;

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
     * Show the campaign's supporters.
     *
     * Every supporter, unordered by anything the operator chose and unpaginated.
     * Both are deliberate and both are recorded rather than overlooked.
     *
     * Order is by arrival, newest first, because `created_at` is the only
     * history this module keeps and it is the one column present on every row.
     * Sorting by family name was the obvious alternative and is the one thing
     * the schema cannot do honestly: a supporter whose source gave one name
     * string has no family name at all, so the list would order some rows and
     * strand the rest — a cost Step 1 accepted knowingly when it chose to
     * record name parts rather than fabricate them. The id breaks ties so the
     * order is total, which matters because two supporters imported in the same
     * second are otherwise free to swap places between requests.
     *
     * Pagination belongs to Step 6, which owns index and query shape once a
     * list is large enough to page rather than render. Today a campaign's list
     * is small enough to send whole, and paging it now would be guessing at a
     * page size with no real list to measure against.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Supporter::class);

        return Inertia::render('supporters/Index', [
            'supporters' => Supporter::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
