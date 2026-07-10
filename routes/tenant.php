<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
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
    /*
     * Resolution probe (thin — not product UI). Proves the request identified
     * its campaign and switched onto that campaign's own database. Replaced by
     * the real tenant landing page once the tenant app has one.
     */
    Route::get('/campaign', function (): array {
        /** @var Tenant $campaign */
        $campaign = tenant();

        return [
            'campaign' => $campaign->only(['id', 'name', 'slug']),
            'database' => DB::connection()->getDatabaseName(),
        ];
    })->name('campaign.home');

    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    });

    require __DIR__.'/settings.php';
});
