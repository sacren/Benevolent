<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * How long the queue's own leftovers live.
 *
 * `failed_jobs` and `job_batches` are central tables, and until this
 * application queued work of its own they were empty, so nothing was owed. A
 * failed import writes a row, and a row that nothing ever removes is a table
 * that grows for as long as the platform runs -- carrying, in the exception
 * column, whatever the thing that failed had to say about why.
 *
 * This file sits in the Feature suite rather than in Tenancy deliberately: the
 * claim is that these run *centrally*, and this is the suite whose tests are
 * served with no campaign initialized and whose central schema is migrated per
 * test. Asserting it from a campaign context would be asserting it in the one
 * place it is not being made.
 */

test('a stale failure is pruned and a recent one is kept', function (): void {
    // The command is the framework's, so what is worth proving is that it
    // reaches the right table here rather than that it filters correctly --
    // and that it reaches it without a campaign, which is where the scheduler
    // will run it.
    $central = (string) config('tenancy.database.central_connection');

    $row = fn (string $uuid, int $daysAgo): array => [
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'irrelevant to this test',
        'failed_at' => now()->subDays($daysAgo),
    ];

    DB::connection($central)->table('failed_jobs')->insert([
        $row('stale-failure', 8),
        $row('recent-failure', 1),
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    Artisan::call('queue:prune-failed --hours=168');

    // Both halves in one place: without the second, a command that emptied the
    // table would satisfy this as convincingly as one that pruned by age.
    expect(DB::connection($central)->table('failed_jobs')->pluck('uuid')->all())
        ->toBe(['recent-failure']);
});

test('the queue tables are pruned centrally, never once per campaign', function (): void {
    // The configuration invariant behind the behaviour above, and it points the
    // opposite way to the reset-token cleanup's. `auth:clear-resets` must be
    // wrapped in `tenants:run` because password_reset_tokens lives in each
    // campaign's own database; these two must not be, because a campaign
    // database has no failed_jobs table at all -- so a wrapped form would not
    // prune less, it would die on a missing relation once per campaign.
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'queue:prune'));

    expect($scheduled)->toHaveCount(2);

    foreach ($scheduled as $command) {
        expect($command)->not->toContain('tenants:run')
            // Seven days rather than the framework's default of one, so a
            // Friday-evening failure is still readable on Monday morning.
            ->and($command)->toContain('--hours=168');
    }

    // Named individually rather than counted, so dropping one and adding
    // another twice cannot pass.
    expect($scheduled->filter(fn (string $c): bool => str_contains($c, 'queue:prune-failed')))->toHaveCount(1)
        ->and($scheduled->filter(fn (string $c): bool => str_contains($c, 'queue:prune-batches')))->toHaveCount(1);

    foreach (collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'queue:prune')) as $event) {
        expect($event->expression)->toBe('0 0 * * *');
    }
});
