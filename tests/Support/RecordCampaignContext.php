<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use ReflectionProperty;

/**
 * A queued job that records which campaign the application was answering as
 * while it ran.
 *
 * A queue worker is the one process that serves several campaigns in
 * succession, which is where every value the application caches per process
 * either follows the campaign or quietly does not. Each bootstrapper that
 * steers such a value has its own test elsewhere, driven by calling
 * tenancy()->initialize() and end() directly; this job is how the same
 * guarantees are asked of the mechanism that actually runs in production, where
 * the switching is done by the queue's own JobProcessing and JobProcessed
 * listeners rather than by the caller.
 *
 * It lives with the tests rather than in an app/Jobs directory because nothing
 * in the application queues anything yet, and a job whose only consumer is a
 * test is not a reason to invent a home in app/.
 */
final class RecordCampaignContext implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * What each job saw, keyed by the label it was dispatched with.
     *
     * A static rather than a file or a table because the worker runs in the
     * test's own process. Statics are not serialized with the job, so the array
     * a job appends to here is the same one the test reads afterwards — and a
     * label that never appears is itself the assertion that a job never ran.
     *
     * @var array<string, array{campaign: ?string, database: string, sender: ?string, url: string, relying_party: ?string, broker_database: string}>
     */
    public static array $observations = [];

    public function __construct(private readonly string $label) {}

    /**
     * Empties the record between tests, which share one process.
     */
    public static function forget(): void
    {
        self::$observations = [];
    }

    public function handle(): void
    {
        self::$observations[$this->label] = [
            // The campaign the job believes it is running for. A right database
            // under a wrong id would mean the connection was switched while the
            // context was not.
            'campaign' => tenant() ? (string) tenant('id') : null,
            'database' => DB::connection()->getDatabaseName(),
            'sender' => is_string($name = config('mail.from.name')) ? $name : null,
            'url' => url('/'),
            'relying_party' => is_string($id = config('passkeys.relying_party_id')) ? $id : null,
            'broker_database' => self::brokerDatabase(),
        ];
    }

    /**
     * The database this campaign's password broker would write a reset token
     * into — the binding whose cached connection was the measured defect that
     * put a bootstrapper in front of it.
     */
    private static function brokerDatabase(): string
    {
        $repository = Password::broker()->getRepository();

        expect($repository)->toBeInstanceOf(DatabaseTokenRepository::class);

        $connection = (new ReflectionProperty($repository, 'connection'))->getValue($repository);

        return $connection->getDatabaseName();
    }
}
