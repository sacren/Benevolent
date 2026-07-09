<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Picks a generated URL apart so an assertion can name the one part it cares
 * about, instead of restating the implementation's own formula back at it.
 *
 * These sit behind a class because Pest loads every test file into a single
 * process: a helper named `hostOf` in the global namespace is one identically
 * named helper in a future test file away from a fatal redeclaration, which
 * aborts the whole run rather than failing a test.
 */
final class Url
{
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    public static function port(string $url): ?int
    {
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }
}
