<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Blast;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
}
