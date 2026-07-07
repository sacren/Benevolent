<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

/*
 | Tenant request resolution is a later Phase 0 step (Blueprint §5 "Tenant
 | resolution per request"). Until then this file is intentionally empty:
 | stancl's default sample route registered an unnamed `GET /` which, being
 | mapped after routes/web.php, overwrote the central `home` route ('/') and
 | broke central-app tests. The route-mapping mechanism in TenancyServiceProvider
 | stays wired, so activating tenant routes later is just a matter of adding them
 | here (scoped to tenant domains) alongside the resolution middleware.
 */
Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    //
});
