<?php

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
