<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Asking for a password reset writes an operator's email address into their
 * campaign's database. The token stops working after an hour
 * (auth.passwords.users.expire), but the row does not go anywhere, so an
 * abandoned reset leaves a personal detail behind indefinitely. This is the
 * framework's own cleanup, which nothing in this application was calling.
 *
 * `tenants:run` is the load-bearing half, for two separate reasons. Operators
 * and their reset tokens live in each campaign's own database (D-1), so central
 * has no password_reset_tokens table at all and the bare command does not clean
 * less -- it dies on a missing relation. And a campaign's tokens can only be
 * reached from inside that campaign's context, which is what this iterates.
 *
 * Daily rather than hourly: the tokens are already unusable long before this
 * runs, so the interval governs how long a spent address lingers, not whether
 * anyone can still use it.
 *
 * Note this is scheduling, not queued work -- no worker is involved, and the
 * background-jobs architecture (Step 13) is untouched. What it does need is a
 * scheduler actually running in the deployed environment (`schedule:work`, or
 * cron invoking `schedule:run`); without one this declaration is inert, and
 * nothing in the application will report that.
 */
Schedule::command('tenants:run auth:clear-resets')
    ->daily()
    ->description('Clear expired password-reset tokens in every campaign');
