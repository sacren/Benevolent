<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Features;

/**
 * What a campaign's database really holds, read as bytes rather than through a
 * model.
 *
 * This is the PII-at-rest position stated as tests. The inventory behind it was
 * measured rather than reasoned about — an operator was created through the
 * real paths and every column read back with the query builder — and it comes
 * out in two halves.
 *
 * **Already protected, and asserted here.** The password is hashed by a cast,
 * the two-factor secret and recovery codes are encrypted by Fortify, and a
 * password-reset token is hashed by the framework's broker. None of that is
 * this application's code, which is exactly why it is worth pinning: nothing
 * else in the suite would notice if a cast were dropped, a factory started
 * writing a secret in the clear, or an upgrade changed a default. A green
 * authentication test does not care whether the password it checked was stored
 * hashed or plain.
 *
 * **Deliberately not encrypted, and deliberately not asserted.** `users.email`
 * is the sign-in lookup and carries a unique index; `password_reset_tokens` is
 * keyed by email as its primary key. Laravel's `encrypted` cast is
 * non-deterministic — a fresh initialization vector per write — so a `where`
 * on a ciphertext can never match, and encrypting either column breaks sign-in,
 * registration and password reset outright. Closing that needs a deterministic
 * blind index beside the ciphertext, which is a build rather than a cast and is
 * recorded as a deferral. `users.name`, `users.remember_token` and the audit
 * trail's denormalized address labels *could* each take a cast today, none
 * being searched, but encrypting a name while the address beside it stays
 * legible buys very little, and the trail's labels are deliberately readable so
 * that a campaign's own history stays readable.
 *
 * There is no assertion here that those columns are in the clear, and that is
 * on purpose: such a test would go red on the exact improvement the deferral
 * describes, which is a tripwire rather than a guard (L-20).
 *
 * Worth knowing about the threat model, because it bounds all of the above:
 * the encryption key lives in the application's environment, so column
 * encryption defends a stolen dump or backup and does nothing against an
 * application compromise. Encryption of the volume a campaign's database sits
 * on is the deployment-side answer and is not application code.
 */
test('an operator password never reaches the database as they typed it', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());

    // Registered through the real endpoint, so the hashing under test is the
    // one that runs in production. Creating the operator with a factory would
    // prove less: the factory hands the model an already-hashed value, so it
    // would still look right with the cast removed.
    $this->post(route('register.store'), [
        'name' => 'Ada Probe',
        'email' => 'ada@example.test',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ]);

    // Read with the query builder rather than the model, throughout this file.
    // An accessor can make any storage look like anything, so a claim about
    // what is *stored* has to bypass the model that interprets it.
    $stored = (string) DB::table('users')->where('email', 'ada@example.test')->value('password');

    // Both halves, together. "Not the passphrase" alone passes against a column
    // holding rubbish; "the passphrase verifies" alone passes against a column
    // holding the passphrase, since checking a value against itself would
    // succeed under a hasher that did nothing.
    expect($stored)->not->toBe('a-memorable-passphrase')
        ->and(Hash::check('a-memorable-passphrase', $stored))->toBeTrue();
});

test('two-factor credentials are encrypted at rest', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $operator = User::factory()->create(['email' => 'ada@example.test']);

    // Fortify's own action, not the factory's `withTwoFactor` state. That state
    // encrypts by hand, so asserting against it would only establish that the
    // factory encrypts — the question here is whether the code path an operator
    // actually walks does.
    app(EnableTwoFactorAuthentication::class)($operator);

    $stored = DB::table('users')->where('email', 'ada@example.test')->first();

    $secret = (string) $stored?->two_factor_secret;
    $codes = (string) $stored?->two_factor_recovery_codes;

    // Decryption is half the pairing and it cannot pass by accident: handed a
    // plaintext column, decrypt() throws rather than returning it, so a
    // regression that stored the secret in the clear turns this red by erroring
    // rather than by a soft assertion. The inequality is the other half, and
    // says the stored form is not simply the value itself.
    $decryptedSecret = Crypt::decrypt($secret);
    $decryptedCodes = Crypt::decrypt($codes);

    expect($secret)->not->toBe($decryptedSecret)
        ->and($decryptedSecret)->not->toBeEmpty()
        ->and($codes)->not->toBe($decryptedCodes)
        ->and(json_decode($decryptedCodes, true))->toBeArray();
});

test('a password-reset token is stored hashed', function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $operator = User::factory()->create(['email' => 'ada@example.test']);

    // The broker is what /forgot-password drives, and it returns the token in
    // the clear precisely because the stored copy is not usable to reconstruct
    // it — which is the claim under test.
    $plain = Password::broker()->createToken($operator);

    $stored = (string) DB::table('password_reset_tokens')->where('email', 'ada@example.test')->value('token');

    expect($stored)->not->toBe($plain)
        ->and(Hash::check($plain, $stored))->toBeTrue();

    // The address beside it is *not* hashed, and cannot be: it is this table's
    // primary key and the column the broker looks a token up by. Asserted here
    // only as the paired positive that the row was found at all -- not as a
    // claim that the address should stay legible, which is the deferred blind
    // index question rather than a settled one.
    expect(DB::table('password_reset_tokens')->where('email', 'ada@example.test')->exists())->toBeTrue();
});
