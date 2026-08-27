<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/*
 * What `.env.example` promises about this application.
 *
 * The file is copied into place by `composer setup` and by both CI workflows,
 * so what it says becomes somebody's environment. It shipped as the untouched
 * scaffold until Phase 7 Step 2 and described a different application: sqlite,
 * a database cache and a `log` mailer. Each value below was wrong here in a way
 * nothing reported, so each is pinned by the property that made it wrong rather
 * than by its spelling -- any PostgreSQL connection, any store that takes tags,
 * any mailer but `log`.
 *
 * Read from the file itself, never from config(): the suite runs under
 * phpunit.xml's own values, so the loaded configuration says nothing about
 * what the example would give a fresh checkout.
 */

/**
 * @return array<string, string|null>
 */
function environmentExample(): array
{
    return Dotenv::parse((string) file_get_contents(base_path('.env.example')));
}

test('the example connects to PostgreSQL', function (): void {
    // Every campaign is its own database, created with CREATE DATABASE, and
    // the tenancy package's managers for other drivers are not what runs here.
    $connection = (string) environmentExample()['DB_CONNECTION'];

    expect(config("database.connections.{$connection}.driver"))->toBe('pgsql');
});

test('the example caches in a store that takes tags', function (): void {
    // Tenancy tags every call made through the Cache facade inside a campaign,
    // and a store without tags throws on the first one (measured at Phase 7
    // Step 2 with the database store). Building the store makes no connection,
    // so this holds on a runner with no Redis.
    $store = (string) environmentExample()['CACHE_STORE'];

    expect(Cache::store($store)->getStore())->toBeInstanceOf(TaggableStore::class);
});

test('the example never writes mail into the application log', function (): void {
    // Invitation and password-reset mail carries a live link, and the `log`
    // mailer writes the whole message, link included, into the application log
    // (Phase 6, D-55).
    $mailer = (string) environmentExample()['MAIL_MAILER'];

    expect(config("mail.mailers.{$mailer}.transport"))->not->toBeNull()
        ->not->toBe('log');
});
