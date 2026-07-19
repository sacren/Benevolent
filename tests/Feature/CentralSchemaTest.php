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

test('the central database does not carry operator identity or credentials', function (): void {
    // The endpoint of D-1, stated as schema: an operator exists only inside a
    // campaign. There is no central `users` table for one to live in, and that
    // absence subsumes the narrower claims -- no central password reset tokens,
    // no central passkeys, and no two-factor columns, because there is no table
    // left to hang them on.
    //
    // All of these were duplicated into both migration sets on purpose while
    // authentication moved into campaign context, so that no commit was ever
    // red. This asserts the duplication is now fully undone on the central side.
    expect(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse()
        ->and(Schema::hasTable('passkeys'))->toBeFalse();
});

test('the central database does not carry an audit trail', function (): void {
    // An audit entry describes something that happened inside one campaign, so
    // it belongs in that campaign's database. A central audit_entries table
    // would pool every campaign's history into one place -- the inverse of the
    // isolation this platform is built on, and undetectable from inside any
    // single campaign, because a shared history table looks exactly like a
    // working audit trail until someone reads another campaign's out of it.
    //
    // This assertion lives in this suite rather than beside the rest of the
    // audit-trail tests for a reason worth keeping. Tests in the campaign suite
    // run against a central database the harness rebuilds only when it is
    // missing, so a migration misfiled into the central set is never applied
    // during that suite and the same assertion holds there unconditionally --
    // it passes whether or not the claim is true. This suite migrates central
    // per test, so misfiling the migration turns this red.
    //
    // Not a hypothetical filing error, either: it is the default. The obvious
    // package for this step publishes its migration to database/migrations/
    // through Spatie's package tools, so adopting one and following its install
    // instructions lands the table here.
    expect(Schema::hasTable('audit_entries'))->toBeFalse();
});

test('the central database does not carry a supporter list', function (): void {
    // The same claim as the trail's above, for the first product data this
    // platform holds -- and the version of the mistake with the most at stake,
    // because a supporter is a member of the public rather than someone who
    // works here. A central supporters table would pool every campaign's people
    // into one place, and would look exactly like a working list from inside any
    // single campaign right up until someone read another campaign's out of it.
    //
    // It lives in this suite for the reason the trail's does: the campaign suite
    // rebuilds the central schema only when it is missing, so the same line
    // there would hold whether or not it were true. This suite migrates central
    // per test, so a migration written into database/migrations/ instead of
    // database/migrations/tenant/ turns this red.
    expect(Schema::hasTable('supporters'))->toBeFalse();
});
