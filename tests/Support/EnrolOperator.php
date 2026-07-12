<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A queued job that writes an operator into whichever campaign is active when
 * it runs.
 *
 * This exists to prove that a job dispatched inside campaign context is still
 * in that campaign's context when a worker later picks it up — operators live
 * only in a campaign's own database, so a row appearing in the right one is
 * proof the job ran connected to it.
 *
 * It lives with the tests rather than in app/Jobs because nothing in the
 * application queues anything yet. The conventions a real job would follow —
 * a tenant-aware base class, retries, queue names — are Step 13's subject, and
 * inventing them around a job that only a test dispatches would be building
 * ahead of a consumer.
 */
final class EnrolOperator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(private readonly string $email) {}

    public function handle(): void
    {
        User::create([
            // The campaign the job believes it is running for, recorded rather
            // than assumed. A row in the right database with the wrong id here
            // would mean the connection was restored but the context was not.
            'name' => (string) tenant('id'),
            'email' => $this->email,
            'password' => 'password',
        ]);
    }
}
