<?php

declare(strict_types=1);

namespace App\MailOutcomes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Where the validator gets the certificate an SNS message was signed with
 * (D-85).
 *
 * **It replaces the validator's own fetch, which has no timeout.** That fetch
 * is a bare `file_get_contents`, bounded only by PHP's `default_socket_timeout`
 * -- 60 seconds in Sail -- while SNS waits about 15 for an answer. A slow AWS
 * endpoint would then turn every notice into a timeout SNS counts as a failed
 * delivery. Five seconds leaves room to verify and write inside SNS's window.
 *
 * **Cached by its URL, and only once it arrived.** AWS publishes a rotated
 * certificate at a new URL, so a URL names one certificate for as long as it
 * exists; the measured cost of not caching is 320-351 ms a message from Sail.
 * A fetch that failed is never cached, so the next message tries again.
 *
 * **A failure is thrown, not returned as `false`.** The validator would read
 * `false` as a message it cannot verify -- a refusal SNS does not retry --
 * when an unreachable AWS endpoint is a reason to answer 5xx and let SNS's
 * retry policy try later. The controller makes that distinction.
 *
 * The validator checks the URL before calling this -- `https`, a `.pem` path,
 * and a host matching `sns.<region>.amazonaws.com` -- so nothing here can be
 * pointed at another host by a message's sender.
 */
final class SigningCertificates
{
    /**
     * How long to wait for AWS before answering SNS with a retryable failure.
     */
    private const int TIMEOUT_SECONDS = 5;

    /**
     * How long one certificate is trusted from the cache.
     */
    private const int CACHE_SECONDS = 86_400;

    public function __invoke(string $url): string
    {
        $key = 'sns-signing-certificate:'.sha1($url);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $certificate = Http::timeout(self::TIMEOUT_SECONDS)->get($url)->throw()->body();

        Cache::put($key, $certificate, self::CACHE_SECONDS);

        return $certificate;
    }
}
