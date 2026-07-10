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
*/

Route::inertia('/', 'Welcome')->name('home');
