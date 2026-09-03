<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Signs SNS envelopes the way AWS does, with a key the suite makes itself.
 *
 * **Why every fixture is re-signed.** The fixtures in tests/Fixtures/sns are
 * real envelopes captured at Step 2 with the account's identifiers replaced,
 * and replacing them breaks AWS's signature -- measured, because `TopicArn` and
 * `Message` are both signed. So each test signs the envelope it sends, and
 * serves this class's certificate at the envelope's `SigningCertURL` through
 * the HTTP fake, which puts the production certificate fetch in the path.
 *
 * **The string to sign is built here from AWS's documented field lists, never
 * from the validator's own code.** A signer that borrowed the validator's
 * `getStringToSign()` would agree with the validator by construction, and a
 * defect in it would pass every test. These lists are the ones Step 2 measured
 * against the five captured envelopes: six fields for a Notification, with
 * `Subject` only when present, and seven for a confirmation.
 *
 * Signature version 1 (RSA with SHA-1), the version every captured envelope
 * carries. The keys are made once per process; making a 2048-bit key costs
 * tens of milliseconds and the suite makes two.
 */
final class SnsSigner
{
    /**
     * @var array<string, list<string>>
     */
    private const array SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /**
     * @var array<string, array{key: OpenSSLAsymmetricKey, certificate: string}>
     */
    private static array $pairs = [];

    /**
     * A fixture envelope, decoded.
     *
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/sns/'.$name.'.json'));

        if ($json === false) {
            throw new RuntimeException('No SNS fixture named '.$name.'.');
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The envelope with its signature replaced by one from the named key, as
     * the JSON body SNS would POST. An envelope whose Type has no field list
     * is signed over the Notification fields, so it carries a real signature
     * of the only shape it could have.
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function sign(array $envelope, string $signer = 'aws'): string
    {
        $fields = self::SIGNED_FIELDS[$envelope['Type']] ?? self::SIGNED_FIELDS['Notification'];
        $content = '';

        foreach ($fields as $field) {
            if (! array_key_exists($field, $envelope)) {
                continue;
            }

            $content .= $field."\n".$envelope[$field]."\n";
        }

        openssl_sign($content, $signature, self::pair($signer)['key'], OPENSSL_ALGO_SHA1);

        $envelope['SignatureVersion'] = '1';
        $envelope['Signature'] = base64_encode($signature);

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The certificate to serve at `SigningCertURL`, for the named key.
     */
    public static function certificate(string $signer = 'aws'): string
    {
        return self::pair($signer)['certificate'];
    }

    /**
     * @return array{key: OpenSSLAsymmetricKey, certificate: string}
     */
    private static function pair(string $signer): array
    {
        if (! isset(self::$pairs[$signer])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            if ($key === false) {
                throw new RuntimeException('OpenSSL could not make a key.');
            }

            $request = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);
            $certificate = $request === false ? false : openssl_csr_sign($request, null, $key, 1);

            if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
                throw new RuntimeException('OpenSSL could not make a certificate.');
            }

            self::$pairs[$signer] = ['key' => $key, 'certificate' => $pem];
        }

        return self::$pairs[$signer];
    }
}
