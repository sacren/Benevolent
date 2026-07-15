<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Asking for a password reset writes an operator's email address into their
 * campaign's database, and nothing there ever removes it again.
 *
 * The token stops working after `auth.passwords.users.expire` minutes, so what
 * is left behind is not a way in — it is personal data outliving the request
 * that justified it, in every campaign, indefinitely. The framework ships the
 * cleanup for exactly this (`auth:clear-resets`) and this application never
 * called it.
 *
 * The reason it was never called is the interesting half, and it is why the
 * schedule reads the way it does. `password_reset_tokens` lives in the tenant
 * migration set, because operators live in their own campaign's database, so a
 * scheduled central `auth:clear-resets` does not merely clean nothing — it dies
 * on a missing table. Reaching campaigns is what `tenants:run` is for. The
 * three tests below are one set: the first proves the command clears what it
 * should and only what it should once reached per campaign, the second proves
 * the central form cannot do the job at all, and the third pins the schedule
 * that invokes it.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Put one stale and one current reset token into the campaign's own database.
 *
 * Written straight into the table rather than through the password broker
 * because `created_at` is the only column any of this turns on — the command
 * filters on age and nothing else — and a real `sendResetLink` cannot produce a
 * row that is already an hour old.
 */
function seedResetTokens(Tenant $campaign): void
{
    tenancy()->initialize($campaign);

    $expiresAfter = (int) config('auth.passwords.users.expire');

    DB::connection('tenant')->table('password_reset_tokens')->insert([
        [
            'email' => 'abandoned@'.$campaign->slug.'.test',
            'token' => 'irrelevant-to-this-test',
            'created_at' => now()->subMinutes($expiresAfter + 1),
        ],
        [
            'email' => 'in-progress@'.$campaign->slug.'.test',
            'token' => 'irrelevant-to-this-test',
            'created_at' => now(),
        ],
    ]);

    tenancy()->end();
}

/**
 * The email addresses the campaign's reset-token table still holds.
 *
 * @return list<string>
 */
function retainedResetTokenEmails(Tenant $campaign): array
{
    tenancy()->initialize($campaign);

    $emails = DB::connection('tenant')
        ->table('password_reset_tokens')
        ->orderBy('email')
        ->pluck('email')
        ->all();

    tenancy()->end();

    return array_map(strval(...), $emails);
}

test('the scheduled cleanup clears expired reset tokens in every campaign, and keeps the live ones', function (): void {
    // Two campaigns, because "every campaign" is the claim. A single one would
    // pass just as happily against a cleanup that only ever reaches whichever
    // campaign happened to be initialized, which is the failure this wrapper
    // exists to prevent.
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'River Path', 'domain' => 'river-path.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $river = Tenant::query()->where('slug', 'river-path')->firstOrFail();

    seedResetTokens($harbor);
    seedResetTokens($river);

    expect(retainedResetTokenEmails($harbor))
        ->toBe(['abandoned@harbor-cleanup.test', 'in-progress@harbor-cleanup.test']);

    // Exactly what the schedule runs, with no --tenants filter, so it reaches
    // every campaign in the registry.
    Artisan::call('tenants:run', ['commandname' => 'auth:clear-resets']);

    // The pairing that makes this a guard rather than a formality: the stale
    // address is gone from both campaigns *and* the live one survives in both.
    // Asserting only the deletion would pass against a cleanup that emptied the
    // table, which would sign every operator mid-reset out of their own recovery.
    expect(retainedResetTokenEmails($harbor))->toBe(['in-progress@harbor-cleanup.test'])
        ->and(retainedResetTokenEmails($river))->toBe(['in-progress@river-path.test']);
});

test('the same cleanup run centrally cannot reach a campaign at all', function (): void {
    // Why the schedule says `tenants:run` and not the bare command. This is the
    // shape the obvious version of this step would have shipped, and it fails
    // loudly rather than quietly — but only if someone runs it, which a nightly
    // scheduled task on a server is precisely the case nobody watches.
    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    seedResetTokens($harbor);

    // Central holds no `password_reset_tokens` — operators and everything
    // belonging to them live in a campaign's own database (D-1). This suite
    // migrates the central schema per test, so the day that stops being true
    // this expectation is what reports it.
    expect(fn () => Artisan::call('auth:clear-resets'))
        ->toThrow(QueryException::class, 'password_reset_tokens');

    // The point of the test: the campaign's stale address is untouched. A
    // central cleanup does not clean less than the per-campaign one, it cleans
    // nothing.
    expect(retainedResetTokenEmails($harbor))
        ->toBe(['abandoned@harbor-cleanup.test', 'in-progress@harbor-cleanup.test']);
});

test('the cleanup is scheduled to reach every campaign daily', function (): void {
    // The configuration invariant behind the two tests above (L-14). They prove
    // the command does the right thing when it is invoked; nothing in them
    // proves anything ever invokes it, and for most of this application's life
    // nothing did. Deleting the line in routes/console.php turns this red, and
    // so does "simplifying" it to the bare `auth:clear-resets` that the test
    // above shows cannot reach a campaign.
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'auth:clear-resets'));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled->sole())->toContain('tenants:run');

    $event = collect(app(Schedule::class)->events())
        ->sole(fn ($event): bool => str_contains((string) $event->command, 'auth:clear-resets'));

    // Daily. The tokens are already unusable an hour after they are written, so
    // this is about how long the address lingers, not about the reset flow.
    expect($event->expression)->toBe('0 0 * * *');
});
