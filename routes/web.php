<?php

use App\Http\Controllers\ProviderNoticeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Routes
|--------------------------------------------------------------------------
|
| Routes served by the platform itself rather than by any one campaign.
| Anything an operator signs in to reach now lives in routes/tenant.php,
| because operators exist only in their own campaign's database.
|
| Phase 0 ends with exactly the two routes below. Every central route that
| carries no domain constraint is one more to pin to the central host on the
| day a campaign needs to own `/`, so a third here is the signal to settle
| that deferred question rather than let the bill grow quietly.
|
*/

Route::inertia('/', 'Welcome')->name('home');

/*
 * Where a central visitor lands when they ask for something only a campaign can
 * serve. Operators live in their own campaign's database, so there is nothing
 * here to sign in to; PreventAccessFromCentralDomains redirects to this page
 * rather than returning a bare 404 (see TenancyServiceProvider).
 *
 * Deliberately static. Helping someone who does not know their campaign's
 * address means asking them for some identifier, and operators now arrive by
 * an invitation whose link already names their campaign's host (D-53) -- the
 * first one from the platform, the rest from the campaign -- so nobody joining
 * is asked which campaign is theirs. A static page is free to replace; a
 * lookup on the wrong key is rework plus retraining. The trigger to add one is
 * the first operator who cannot find their campaign again (deferral 2).
 */
Route::inertia('/campaign-sign-in', 'CampaignSignIn')->name('campaign-sign-in');

/*
 * Where SNS delivers the mail provider's notices (D-85) -- the third central
 * route, and the one that fires deferral 1, so it is pinned to the central
 * host from birth rather than added to the bill the comment above describes.
 *
 * **Once per central host, because `central_domains` is a list.** A campaign's
 * host, and any host nobody registered, finds no route here and is answered
 * 404, so a notice can only ever be processed as the platform. The routes
 * carry no name: one name cannot belong to several routes, and nothing
 * generates this URL -- it is typed into AWS when the subscription is made.
 *
 * **Outside the `web` group, not merely exempt from CSRF.** SNS sends no
 * session, cookie or token, and under the group every notice would still
 * start a session -- one more central `sessions` row per notice in production,
 * where the driver is the database -- and set a cookie nobody reads.
 */
foreach (config('tenancy.central_domains') as $centralDomain) {
    Route::domain($centralDomain)
        ->post('/mail-outcomes/sns', ProviderNoticeController::class)
        ->withoutMiddleware('web');
}
