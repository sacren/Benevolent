<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Routes served in the context of a single campaign. The campaign is
| identified by the request's full Host header, matched against a `domains`
| row (D-2 → Full domain identification), and the default database connection
| is switched onto that campaign's own database before the route runs.
|
| Two guards, both raised to the highest middleware priority by
| TenancyServiceProvider so they run ahead of the `web` group:
|
|   - PreventAccessFromCentralDomains — a central host (config
|     `tenancy.central_domains`) reaching a tenant route gets a 404.
|   - InitializeTenancyByDomain — resolves the campaign and switches the
|     connection; an unknown host cannot reach these routes at all.
|
| Path convention: tenant routes never register a bare `/`. Laravel keys a
| route by domain + URI, so an undomained tenant `GET /` collides with the
| central `home` route ('/') and, being mapped after routes/web.php, replaces
| it — which is exactly what broke `route('home')` before (Learning L-2). A
| Route::domain() constraint is not available as a fix either: campaign
| hostnames are data, not statically known. So tenant routes live under
| distinct, tenant-named paths.
|
*/

Route::middleware([
    'web',
    PreventAccessFromCentralDomains::class,
    InitializeTenancyByDomain::class,
])->group(function (): void {
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
});
