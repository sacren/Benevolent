<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * A queued job that records which campaign it ran in and then throws.
 *
 * Every attempt is recorded, including the one a retry produces, which is what
 * lets a test ask the question that matters about a failure under
 * database-per-tenant: not merely whether the failure was written down, but
 * whether the work goes back to the campaign it belongs to when someone asks
 * for it again.
 */
final class FailInsideCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The campaign each attempt ran in, in order, with null for central.
     *
     * @var list<?string>
     */
    public static array $attempts = [];

    /**
     * Empties the record between tests, which share one process.
     */
    public static function forget(): void
    {
        self::$attempts = [];
    }

    public function handle(): void
    {
        // Recorded before throwing, so an attempt that ran in the wrong campaign
        // is distinguishable from one that never ran at all.
        self::$attempts[] = tenant() ? (string) tenant('id') : null;

        throw new RuntimeException('This job exists to fail.');
    }
}
