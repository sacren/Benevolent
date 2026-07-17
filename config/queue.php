<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        /*
        | The queue's own tables are pinned to the central connection rather
        | than left null, for the same reason `session.connection` is. Queued
        | work is shared platform infrastructure, not per-campaign data, so
        | `jobs` and `job_batches` live in the central database only. A null
        | value resolves to the *default* connection, which tenancy has already
        | switched onto the campaign's own database by the time anything is
        | dispatched from a campaign route — so the insert would look for a
        | `jobs` table the campaign database does not have.
        |
        | Pinning is also what makes the behavior deterministic. A resolved
        | queue connection is cached for the life of the process and keeps
        | whichever database connection was default when it was built, so left
        | unpinned, *which* database a job row lands in depends on when in the
        | process the queue was first resolved.
        |
        | Campaign context is not lost by keeping the row central: it travels in
        | the job payload as `tenant_id`, which QueueTenancyBootstrapper stamps
        | on dispatch and reads back before the job runs. Note the separate
        | per-connection `central` key that bootstrapper also honors — it means
        | "jobs here are not campaign-aware", the opposite of this pin, and
        | setting it would strip that context.
        */
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION', env('DB_CONNECTION', 'pgsql')),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    /*
    | Both of the keys below name a connection for the same reason the queue's
    | own does: `job_batches` and `failed_jobs` are central tables, and a null
    | here would mean "whatever connection is currently default", which tenancy
    | has repointed at a campaign's database whenever campaign work is involved.
    |
    | Neither is ours -- the framework already names the central connection here
    | -- so this is a note rather than a change, and the invariant is asserted in
    | tests/Tenancy/CampaignFailedJobTest.php so an upgrade or an environment
    | cannot quietly loosen it. What that test also measured is where the fault
    | would actually appear, which is not where it reads as though it would:
    | recording a failure survives an unpinned key, because the queue
    | bootstrapper reverts tenancy before the failure is written, while
    | `queue:retry` enters the campaign to re-queue the job and then cannot find
    | the failed row it is finishing with.
    |
    | A failed job keeps its campaign the same way a queued one does, in the
    | payload's `tenant_id`, so nothing is lost by these rows being central --
    | but note that the row itself has no campaign column, so "which campaign's
    | work is failing" is a question about payloads rather than one `queue:failed`
    | can answer.
    */
    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
