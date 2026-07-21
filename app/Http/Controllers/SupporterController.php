<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Supporters\StoreSupporterRequest;
use App\Http\Requests\Supporters\UpdateSupporterRequest;
use App\Models\Supporter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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
}
