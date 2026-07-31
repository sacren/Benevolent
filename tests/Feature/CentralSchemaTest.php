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

test('the central database does not carry a campaign\'s blasts', function (): void {
    // The same claim again for the first thing this platform does that leaves
    // it -- and the one where a reader would most reasonably guess wrong, since
    // "sent mail" sounds like platform infrastructure and the `jobs` and
    // `failed_jobs` tables beside it genuinely are. A central blasts table would
    // let a reader of one campaign see what another campaign said to its
    // supporters, in the campaign's own name.
    //
    // It lives in this suite for the reason the trail's and the list's do: the
    // campaign suite rebuilds the central schema only when it is missing, so the
    // same line there would hold whether or not it were true (L-18). This suite
    // migrates central per test, so a migration written into
    // database/migrations/ instead of database/migrations/tenant/ turns this
    // red -- measured, not assumed.
    //
    // **What this line is worth was measured, and it is worth less than the two
    // above it.** Misfiling the blasts migration as it actually stands does not
    // reach this assertion at all: the table carries a foreign key to `users`,
    // which exists only inside a campaign, so the migration dies centrally with
    // `relation "users" does not exist` and every test in this file errors
    // before asserting anything. Dropping that key and misfiling the migration
    // then turns *this* line, and only this line, red. So today it is the second
    // catch rather than the first, which is the same relationship
    // `supporter_imports` has -- and that table has no assertion here at all.
    //
    // Kept anyway, and the reason is the future rather than the present: the
    // foreign key is protection this table happens to have, not a property of
    // being campaign-scoped. `supporters` and `audit_entries` have no such key,
    // which is why their lines are the only catch. Should `operator_id` ever
    // lose its constraint, the misfiling goes silent and this line is what is
    // left. It costs one line to hold that open.
    expect(Schema::hasTable('blasts'))->toBeFalse();
});

test('the central database does not carry who a campaign has written to', function (): void {
    // The sharpest form of the claim above, because these rows *are* the list of
    // members of the public a campaign contacted -- pooled centrally, they would
    // let a reader of one campaign learn which people another campaign wrote to,
    // which is the one thing DEC-1's physical separation exists to make
    // impossible.
    //
    // Same suite, same reason (L-18): the campaign suite rebuilds the central
    // schema only when it is missing, so this line there would hold whether or
    // not it were true. This one migrates central per test.
    //
    // **Its worth was measured before it was believed, and it is the weakest of
    // the four -- for a reason the blasts line above already records against
    // itself.** `blast_recipients` carries foreign keys to `blasts` and
    // `supporters`, neither of which exists centrally, so misfiling this
    // migration kills it on the first of those and errors every test in this
    // file before an assertion runs. Only with both keys dropped does this line
    // become the thing that reports. Kept for the same reason: the keys are
    // protection this table happens to have rather than a property of being
    // campaign-scoped, and if either is ever loosened this is what is left.
    expect(Schema::hasTable('blast_recipients'))->toBeFalse();
});
