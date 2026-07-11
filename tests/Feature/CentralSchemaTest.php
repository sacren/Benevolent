<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('the central database carries the platform infrastructure tables', function (): void {
    // What the central database is *for*: the campaign registry, plus the shared
    // web infrastructure that is platform-owned rather than campaign-owned.
    // Sessions in particular stay here deliberately (see L-7) -- they are not
    // operator identity, so they do not follow operators into a campaign.
    expect(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('domains'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasTable('cache'))->toBeTrue()
        ->and(Schema::hasTable('jobs'))->toBeTrue();
});

test('the central database does not carry passkeys or two-factor credentials', function (): void {
    // Passkeys and two-factor secrets are operator credentials, so they belong
    // in the campaign database alongside the operator they authenticate. The
    // passkeys foreign key could not have spanned two databases in any case.
    //
    // These were duplicated into both migration sets on purpose while auth moved
    // into campaign context, so that nothing was ever red. This asserts the
    // duplication has been undone on the central side. `users` itself is still
    // here for one more commit, and its own absence is asserted then.
    expect(Schema::hasTable('passkeys'))->toBeFalse()
        ->and(Schema::hasColumns('users', [
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ]))->toBeFalse();
});
