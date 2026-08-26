<?php

declare(strict_types=1);

use App\Http\Controllers\BlastController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\OperatorInvitationController;
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
| Fortify's own login, password-reset, two-factor and passkey routes are
| served here too. It refuses central hosts and then resolves the campaign; an
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

        // The campaign's roster: who runs it and who has been invited to
        // (D-57). Authority is settled by OperatorPolicy inside the
        // controller, for the reason every route above carries no `can:`
        // middleware.
        Route::get('operators', [OperatorController::class, 'index'])->name('operators.index');

        // Changing what somebody on the roster may do (D-57). `{operator}` is
        // a user id, which restarts at 1 in every campaign, so which operator
        // it names is the Host header's to say, exactly as for a blast.
        Route::patch('operators/{operator}', [OperatorController::class, 'update'])->name('operators.update');

        // Removing somebody else from the roster (D-57). Leaving is the
        // profile page's act, and OperatorPolicy refuses it here.
        Route::delete('operators/{operator}', [OperatorController::class, 'destroy'])->name('operators.destroy');

        // Admitting somebody to the campaign (D-53). Authority is settled by
        // OperatorInvitationPolicy inside the controller, for the reason every
        // route above carries no `can:` middleware. Under `operators/`, the
        // noun this module is about, rather than `invitation/`, which is the
        // public path the invitee's link opens.
        Route::get('operators/invite', [OperatorInvitationController::class, 'create'])->name('operators.invitations.create');
        Route::post('operators/invite', [OperatorInvitationController::class, 'store'])->name('operators.invitations.store');

        // Taking an unused invitation back (D-57). Addressed by the row's id
        // rather than its credential: the Owner is not the invitee, and the
        // credential never leaves the invitee's mail. The id restarts at 1 in
        // every campaign, so which invitation it names is the Host header's to
        // say, exactly as for a blast.
        Route::delete('operators/invitations/{invitation}', [OperatorInvitationController::class, 'destroy'])->name('operators.invitations.destroy');
    });

    /*
     * Leaving a campaign's list, which is the one thing here a supporter does
     * for themselves.
     *
     * Deliberately OUTSIDE the ['auth', 'verified'] group above and inside the
     * `tenant` group -- the first page-rendering route in this application to
     * sit that way round, and the invitation routes below are the second. A
     * supporter has no account and never will, so requiring one would make the
     * opt-out reachable exactly by the people who do not need it. It stays
     * inside `tenant` because the campaign is what identifies them: the same
     * person on two campaigns' lists is two supporters in two databases, and
     * the Host header is what says which.
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
     * (L-24), because this was the first endpoint in this application that
     * anybody at all can reach.
     */
    Route::middleware('throttle:unsubscribe')->whereUuid('token')->group(function (): void {
        Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
        Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
    });

    /*
     * Becoming one of this campaign's operators, by the link it sent (D-53).
     *
     * The unsubscribe block's shape, for its reasons: outside the ['auth',
     * 'verified'] group, because the person has no account until this
     * succeeds; inside `tenant`, because the campaign is what the invitation
     * belongs to and a link minted in one campaign must match no row in
     * another; and never in routes/web.php, whose central surface stays at two
     * routes (deferral 1).
     *
     * `{invitation:token}` binds the row by its credential, so an unknown,
     * spent or expired link is refused by the router before anything validates
     * -- a spent invitation holds no token, and an expired one is bound to
     * nothing because OperatorInvitation binds `token` to live rows only
     * (D-59). `whereUuid` is not decoration: `operator_invitations.token` is a
     * `uuid` column, and a malformed value compared against it raises SQLSTATE
     * 22P02 rather than matching nothing.
     *
     * `guest`, as Fortify gave its own registration route: accepting signs the
     * new operator in, and doing that over somebody's existing session would
     * swap one operator for another mid-work. Metered by a limiter keyed on the
     * caller and never on the campaign (L-24), like the unsubscribe routes.
     */
    Route::middleware(['guest', 'throttle:invitation'])->whereUuid('invitation')->group(function (): void {
        Route::get('invitation/{invitation:token}', [InvitationController::class, 'show'])->name('invitation.show');
        Route::post('invitation/{invitation:token}', [InvitationController::class, 'store'])->name('invitation.accept');
    });

    require __DIR__.'/settings.php';
});
