<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * Runs queued jobs the way a worker does, without running a worker.
 *
 * `queue:work` is a long-lived process outside a test's control, and pointing
 * one at the developer's own queue is not this suite's business, so a test
 * drives the worker itself for exactly as many jobs as it dispatched.
 *
 * It has to be the worker rather than a call to the job's own handle(): a job's
 * campaign is restored by a listener on the JobProcessing event, which only the
 * worker raises. Calling handle() directly would prove nothing about
 * propagation, because the campaign would still be whatever the test left
 * active.
 *
 * Note what this deliberately does *not* reproduce. The row in `failed_jobs` is
 * written by a JobFailed listener that the `queue:work` command registers when
 * it starts, not by the worker underneath it — so a job that throws during one
 * of these passes is deleted from the queue and leaves no record anywhere. A
 * test that wants to assert a failure has to register that listener itself.
 *
 * These sit behind a class rather than a global helper for the reason given in
 * Tests\Support\Url: Pest loads every test file into one process, so a global
 * function is one identically named helper away from a fatal redeclaration.
 */
final class QueueWorker
{
    /**
     * One attempt per job, matching `queue:work --tries=1`.
     *
     * Pinned here rather than left to WorkerOptions' own default, which is not a
     * stable thing to depend on — it is 1 in this framework and has been 0,
     * meaning retry forever, under which a job that throws is released for
     * another pass instead of being given up on.
     */
    private const int MAX_TRIES = 1;

    /**
     * Processes exactly one queued job.
     */
    public static function runNextJob(): void
    {
        // Resolved by its container alias rather than its class name: the worker
        // takes a maintenance-mode callable the container cannot autowire, and
        // the alias is the binding `queue:work` itself uses.
        $worker = app('queue.worker');

        expect($worker)->toBeInstanceOf(Worker::class);

        $worker->runNextJob('database', 'default', new WorkerOptions(maxTries: self::MAX_TRIES));
    }

    /**
     * Processes the next $count queued jobs, in the order the queue hands them
     * over — which is what makes one process serve several campaigns in turn.
     */
    public static function runNextJobs(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            self::runNextJob();
        }
    }
}
