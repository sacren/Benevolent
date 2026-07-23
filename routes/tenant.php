<?php

declare(strict_types=1);

use App\Http\Controllers\SupporterController;
use App\Http\Controllers\SupporterImportController;
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
    });

    require __DIR__.'/settings.php';
});
