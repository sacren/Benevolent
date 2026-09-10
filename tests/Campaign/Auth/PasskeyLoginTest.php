<?php

declare(strict_types=1);

/*
 * What an unauthenticated passkey sign-in costs before it is refused (D-98).
 *
 * POST passkeys/login answers anyone. Its form request deserializes the posted
 * credential before the controller reads the session's challenge, and the
 * authenticator data is CBOR, whose heads declare their own lengths. Measured
 * against copies of the tree on PHP 8.5.0: web-auth/webauthn-lib 5.3.12 read a
 * declared 64 MiB byte string by allocating it (a 64.12 MiB peak from a body
 * of a few dozen bytes, and under a 128M limit an uncatchable fatal for
 * 256 MiB), while 5.3.17 refuses it at 0.17 MiB. Both answer the same 422.
 *
 * **The two tests are a pair.** The honest credential shows the request gets
 * past the deserializer to the missing challenge, so the hostile one's refusal
 * is a refusal on that path and not a request turned away before it.
 */

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function (): void {
    // The passkeys limiter reaches the cache outside the tenancy wrapper's
    // tags, so its counters would otherwise survive between tests.
    app('cache')->driver()->flush();
});

/**
 * Authenticator data with a zero relying-party hash and counter, the given
 * flags, and whatever follows them.
 */
function passkeyAuthenticatorData(int $flags, string $rest = ''): string
{
    return str_repeat("\0", 32).chr($flags).pack('N', 0).$rest;
}

/**
 * Post an assertion carrying this authenticator data to the login endpoint.
 */
function postPasskeyAssertion(TestCase $test, string $url, string $idSeed, string $authenticatorData): TestResponse
{
    $base64Url = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $credentialId = hash('sha256', $idSeed, true);

    return $test->postJson($url, [
        'credential' => [
            'id' => $base64Url($credentialId),
            'rawId' => base64_encode($credentialId),
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => $base64Url($authenticatorData),
                'clientDataJSON' => $base64Url((string) json_encode([
                    'type' => 'webauthn.get',
                    'challenge' => $base64Url(str_repeat("\1", 32)),
                    'origin' => 'http://'.parse_url($url, PHP_URL_HOST),
                ])),
                'signature' => $base64Url(str_repeat("\2", 64)),
            ],
        ],
    ]);
}

test('an honest credential passes the deserializer and is refused for the missing challenge', function () {
    $response = postPasskeyAssertion(
        $this,
        $this->campaignUrl('passkeys/login'),
        'honest',
        passkeyAuthenticatorData(0x01),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => 'Passkey verification session expired. Please try again.']);
    $this->assertGuest();
});

test('a credential declaring a 64 MiB byte string is refused without allocating it', function () {
    $declaredBytes = 64 * 1024 * 1024;
    $extensionsHead = "\x5B".pack('J', $declaredBytes);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage();

    $response = postPasskeyAssertion(
        $this,
        $this->campaignUrl('passkeys/login'),
        'hostile',
        passkeyAuthenticatorData(0x81, $extensionsHead),
    );
    $peakGrowth = memory_get_peak_usage() - $memoryBefore;

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => 'Invalid credential format.']);
    $this->assertGuest();
    expect($peakGrowth)->toBeLessThan(32 * 1024 * 1024);
});
