<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Throwable;

/**
 * Removes the campaigns the test harness provisioned.
 *
 * A campaign database is created outside any transaction — PostgreSQL forbids
 * CREATE DATABASE inside one — so nothing rolls it back when a test ends, and
 * deleting the campaign through its model to reclaim it costs as much as
 * creating it did. The harness therefore provisions one campaign per test file
 * and cleans up here, once, at process exit.
 *
 * Only campaigns this class was handed are touched. Sweeping for databases by
 * their `tenant%` name prefix would be far simpler and quite wrong: the test
 * database shares a PostgreSQL server with the developer's own, so a prefix
 * match would also find, and drop, the campaigns they are working on.
 */
final class ProvisionedCampaigns
{
    /** @var array<string, string> Campaign key => its database name. */
    private static array $campaigns = [];

    /** @var array<string, mixed>|null */
    private static ?array $credentials = null;

    private static bool $registered = false;

    /**
     * Record a freshly provisioned campaign for removal at process exit.
     */
    public static function track(string $campaignKey, string $database): void
    {
        self::$campaigns[$campaignKey] = $database;

        // Captured while the application is still booted: the shutdown handler
        // runs after it has been torn down and cannot read config itself.
        if (self::$credentials === null) {
            $central = (string) config('tenancy.database.central_connection');
            $config = config('database.connections.'.$central);

            self::$credentials = is_array($config) ? $config : null;
        }

        if (! self::$registered) {
            self::$registered = true;

            register_shutdown_function(static fn () => self::removeAll());
        }
    }

    /**
     * Drop every recorded database and forget its registry row.
     */
    public static function removeAll(): void
    {
        if (self::$campaigns === [] || self::$credentials === null) {
            return;
        }

        $pdo = self::connectToCentral(self::$credentials);

        if (! $pdo instanceof PDO) {
            return;
        }

        foreach (self::$campaigns as $campaignKey => $database) {
            try {
                // A test file that rebuilt the central schema may have dropped
                // this database already, hence IF EXISTS rather than a failure.
                $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', self::quoteIdentifier($database)));

                // The registry row outlives the database whenever nothing runs
                // after the campaign suite. Its `domains` rows cascade away with
                // it. If the table itself is gone, there is nothing to clean.
                $statement = $pdo->prepare('DELETE FROM tenants WHERE id = ?');
                $statement->execute([$campaignKey]);
            } catch (Throwable) {
                // Best effort: a leftover row or database is a nuisance, whereas
                // throwing here would fail an otherwise green run at teardown.
                continue;
            }
        }

        self::$campaigns = [];
    }

    /**
     * Connect to the central database with the credentials it is configured
     * with, or not at all.
     *
     * Every value below comes from the central connection's own config. There
     * are deliberately no fallbacks: a default host or port would connect
     * somewhere real but wrong, drop nothing, and report success — so a missing
     * key returns null and leaves the cleanup visibly undone instead.
     *
     * @param  array<string, mixed>  $credentials
     */
    private static function connectToCentral(array $credentials): ?PDO
    {
        // Database-per-tenant is realized here by separate PostgreSQL databases,
        // so a different driver means this helper's DSN no longer describes
        // reality and should stop rather than guess.
        if (($credentials['driver'] ?? null) !== 'pgsql') {
            return null;
        }

        foreach (['host', 'port', 'database', 'username'] as $required) {
            if (! isset($credentials[$required]) || $credentials[$required] === '') {
                return null;
            }
        }

        try {
            return new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string) $credentials['host'],
                    (string) $credentials['port'],
                    (string) $credentials['database'],
                ),
                (string) $credentials['username'],
                (string) ($credentials['password'] ?? ''),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
