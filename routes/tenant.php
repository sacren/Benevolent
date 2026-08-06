<?php

declare(strict_types=1);

use App\Http\Controllers\BlastController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SupporterController;
use App\Http\Controllers\SupporterImportController;
use App\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Campaign Routes
|--------------------------------------------------------------------------
|
| Routes served in the context of a single campaign. The campaign is
| identified by the request's full Host header, matched against a `domains`
| row, and the default database connection is switched onto that campaign's
| own database before the route runs.
|
| The `tenant` middleware group these sit behind is defined in
| bootstrap/app.php, so that config/fortify.php can point at it by name and
| Fortify's own login, registration, two-factor and passkey routes are served
| here too. It refuses central hosts and then resolves the campaign; an
| unregistered host cannot reach these routes at all.
|
| Path convention: campaign routes never register a bare `/`. Laravel keys a
| route by domain + URI, so an undomained campaign `GET /` collides with the
| central `home` route and, being mapped after routes/web.php, replaces it --
| name lookup included. A Route::domain() constraint is no fix either, since
| campaign hostnames are data rather than statically known, and a wildcard one
| would match the central host as well. So campaign routes live under distinct
| paths.
|
*/

Route::middleware('tenant')->group(function (): void {
    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');

        // The supporter list. Authority is settled by SupporterPolicy inside
        // the controller rather than by a `can:` middleware here, so that the
        // ability a route checks and the ability its action performs cannot
        // drift apart -- and so the mapping from ability to permission stays
        // in the one class that owns it.
        Route::get('supporters', [SupporterController::class, 'index'])->name('supporters.index');
        Route::get('supporters/create', [SupporterController::class, 'create'])->name('supporters.create');
        Route::post('supporters', [SupporterController::class, 'store'])->name('supporters.store');
        Route::get('supporters/{supporter}/edit', [SupporterController::class, 'edit'])->name('supporters.edit');
        Route::patch('supporters/{supporter}', [SupporterController::class, 'update'])->name('supporters.update');
        Route::delete('supporters/{supporter}', [SupporterController::class, 'destroy'])->name('supporters.destroy');

        // Taking the list back out, as one file. A literal path beside
        // `supporters/import` and safe for the same reason: there is no
        // `GET supporters/{supporter}` route for it to be mistaken for.
        //
        // Deliberately a GET with no state to change, so the browser can follow
        // it as an ordinary navigation -- which is what a file response needs.
        // An Inertia visit would ask for a JSON page object and get a CSV.
        Route::get('supporters/export', [SupporterController::class, 'export'])->name('supporters.export');

        // Bringing an existing list in. Two steps rather than one: the upload
        // arrives first so the file's own headers can be read, and only then is
        // the operator asked what those headers mean -- which is what keeps the
        // importer from ever guessing at a column. Authority is settled by
        // SupporterPolicy's `import` ability inside the controller, for the same
        // reason the routes above carry no `can:` middleware.
        //
        // `supporters/import` is a literal path and cannot be mistaken for a
        // supporter: there is no `GET supporters/{supporter}` route to collide
        // with, only `supporters/{supporter}/edit`.
        Route::get('supporters/import', [SupporterImportController::class, 'create'])->name('supporters.imports.create');
        Route::post('supporters/import', [SupporterImportController::class, 'store'])->name('supporters.imports.store');
        Route::get('supporters/imports/{import}', [SupporterImportController::class, 'show'])->name('supporters.imports.show');
        Route::post('supporters/imports/{import}', [SupporterImportController::class, 'start'])->name('supporters.imports.start');

        // The messages the campaign has written. Authority is settled by
        // BlastPolicy inside the controller rather than by a `can:` middleware
        // here, for the same reason the supporter routes above carry none.
        Route::get('blasts', [BlastController::class, 'index'])->name('blasts.index');
        Route::get('blasts/create', [BlastController::class, 'create'])->name('blasts.create');
        Route::post('blasts', [BlastController::class, 'store'])->name('blasts.store');
        Route::get('blasts/{blast}/edit', [BlastController::class, 'edit'])->name('blasts.edit');
        Route::patch('blasts/{blast}', [BlastController::class, 'update'])->name('blasts.update');

        // Committing a blast to sending. A POST rather than a PATCH, and its
        // own path rather than a field on the update form: this is not an edit
        // of the blast, it is the one act in this module that cannot be taken
        // back, and a form that could reach it by submitting the compose fields
        // would be one stray input away from sending a draft somebody was still
        // writing. Authority is settled by BlastPolicy's `send` ability inside
        // the controller, like every route above it.
        Route::post('blasts/{blast}/send', [BlastController::class, 'send'])->name('blasts.send');

        // The narrowings a campaign has named for its own supporter list.
        // Authority is settled by SegmentPolicy inside the controller rather
        // than by a `can:` middleware here, for the reason every route above
        // carries none.
        //
        // `segments/create` is a literal path and cannot be mistaken for a
        // segment: there is no `GET segments/{segment}` route to collide with,
        // only `segments/{segment}/edit`.
        Route::get('segments', [SegmentController::class, 'index'])->name('segments.index');
        Route::get('segments/create', [SegmentController::class, 'create'])->name('segments.create');
        Route::post('segments', [SegmentController::class, 'store'])->name('segments.store');
        Route::get('segments/{segment}/edit', [SegmentController::class, 'edit'])->name('segments.edit');
        Route::patch('segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
        Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');
    });

    /*
     * Leaving a campaign's list, which is the one thing here a supporter does
     * for themselves.
     *
     * Deliberately OUTSIDE the ['auth', 'verified'] group above and inside the
     * `tenant` group -- the only page-rendering route in this application that
     * sits that way round. A supporter has no account and never will, so
     * requiring one would make the opt-out reachable exactly by the people who
     * do not need it. It stays inside `tenant` because the campaign is what
     * identifies them: the same person on two campaigns' lists is two
     * supporters in two databases, and the Host header is what says which.
     *
     * For the same reason this belongs here and never in routes/web.php. A
     * central unsubscribe route would have to be told which campaign it meant,
     * which is either a parameter anybody can change or a lookup across every
     * campaign's database -- and the central surface stays at exactly two
     * routes (deferral 1's tripwire).
     *
     * `whereUuid` is stated once, on the group, rather than twice. It is not
     * decoration: `supporters.unsubscribe_token` is a `uuid` column, so a
     * malformed token compared against it raises SQLSTATE 22P02 rather than
     * matching no rows -- a 500 for anybody who mistypes a link, with the
     * offending value inlined into the exception message. The constraint makes
     * the router answer 404 before a query is ever built.
     *
     * Metered by a limiter keyed on the caller and never on the campaign
     * (L-24), because this is the first endpoint in this application that
     * anybody at all can reach.
     */
    Route::middleware('throttle:unsubscribe')->whereUuid('token')->group(function (): void {
        Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
        Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
    });

    require __DIR__.'/settings.php';
});
