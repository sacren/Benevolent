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

/*
 * The queue's own tables, which stopped being empty when the first job in this
 * application was queued.
 *
 * `failed_jobs` and `job_batches` are central -- platform infrastructure rather
 * than campaign data -- so these are scheduled plainly, with no `tenants:run`
 * in front of them. That is the opposite of `auth:clear-resets` above, and the
 * contrast is the whole reason both are worth pinning: a campaign database has
 * no `failed_jobs` table at all, so wrapping these would not clean less, it
 * would die on a missing relation once per campaign.
 *
 * Why this is owed now rather than earlier: until the import there was nothing
 * to fail, so both tables stayed empty and pruning nothing was honest. A failed
 * import writes a row, and that row is not merely clutter. The payload
 * deliberately carries an identifier rather than any supporter's details, and
 * the one measured path by which a database error carried a name and address
 * into `failed_jobs.exception` is closed at its source in ImportSupporters --
 * but an exception message is written by whatever threw it, so bounding how
 * long these rows live is the part that does not depend on getting every future
 * thrower right.
 *
 * Seven days rather than the framework's default of one. A failure that happens
 * on a Friday evening should still be there on Monday morning, and nobody
 * debugs an import from a row that was pruned before they read the report.
 *
 * `queue:prune-batches` has no writer today -- nothing in this application
 * dispatches a batch -- and it is scheduled anyway, which is a deliberate
 * exception to building only for consumers that exist. Nothing here is being
 * designed or guessed: it is the framework's own command with the framework's
 * own semantics, it costs one scheduled run against an empty table, and the
 * failure mode of leaving it out is a table that grows without limit from the
 * day somebody dispatches a batch, with nothing to report that it is happening.
 *
 * Both declarations are inert without a scheduler running in the deployed
 * environment (`schedule:work`, or cron invoking `schedule:run`), exactly as
 * the reset-token cleanup above is -- and nothing in the application will say
 * so if there is not one.
 */
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->description('Prune failed jobs older than seven days');

Schedule::command('queue:prune-batches --hours=168')
    ->daily()
    ->description('Prune job batches older than seven days');
