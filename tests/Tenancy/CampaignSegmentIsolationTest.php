<?php

declare(strict_types=1);

use App\Models\Segment;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The DEC-1 isolation guarantee, restated for the third module.
 *
 * CampaignIsolationTest proves a campaign cannot read another campaign's
 * operators; CampaignSupporterIsolationTest proves it for the people a campaign
 * is trying to reach; CampaignBlastIsolationTest proves it for what a campaign
 * says to them. This proves it for the *aim* — which is the one a reader would
 * most reasonably guess is shared, because a segment stores a rule, a rule
 * looks like configuration rather than like data, and configuration sounds
 * central. It is not: "everyone in 902" names a different set of human beings
 * in every campaign. Nothing enforces the isolation separately — it falls out
 * of `App\Models\Segment` naming no connection, so the model follows the
 * default one tenancy has switched onto the campaign serving the request.
 *
 * **What this file is worth was measured rather than claimed, and it is not the
 * last line of defence.** With this file removed and the pooled-segments defect
 * rebuilt the way somebody who believed a rule were platform configuration
 * would actually ship it — the model naming the central connection, with the
 * migration filed into the central set so there is a table for it to reach —
 * **nine other tests still report it**: six failures and three errors, in a
 * suite of 414. The number is recorded rather than an adjective, for the reason
 * the supporter and blast files record theirs: a guard whose docblock claims
 * more than it does is believed without ever being checked, which is a guard
 * that cannot fail wearing better clothes.
 *
 * **Two of those nine report it in a shape the sibling modules could not
 * produce, and they are worth naming because they are the unique index
 * talking.** A pooled `segments` table is not merely readable by the wrong
 * campaign; it *accumulates*, so one storage test failed with `6 is identical
 * to 2` on a count it had every right to expect, and the case-variant test
 * errored on a unique violation against the central `testing` database for a
 * name a previous test had used. Rows leaking between tests is the same defect
 * as rows leaking between campaigns, seen from the only angle a single-campaign
 * run can see it from.
 *
 * **Both halves of the defect are needed, and the half-mutation was run here
 * rather than inherited from the sibling files that record it.** Naming the
 * connection *alone*, with the migration left in the tenant set, errors both
 * tests below with `Database connection [central] not configured` — a typo
 * rather than the hazard, and a red that says nothing about this guard. It is
 * the pair — a central connection *and* a central table for it to reach — that
 * makes test one report Ridge Restoration holding Harbor Cleanup's
 * `Dockside streets`, and makes test two report the refusal predicted two
 * paragraphs above.
 *
 * **Two things it carries that nothing else does, and the second is sharper
 * here than in either sibling file.** Only the second test states that a
 * segment's identity is campaign-local. And here that claim has teeth the
 * others lacked: `segments.name` is unique, so a pooled table would not leak
 * quietly — it would **refuse** the second campaign the right to name a segment
 * the first had already used. Two campaigns running in two different districts
 * would compete for the word "Culver City", and the loser would be told their own
 * campaign already had one. That is a defect no assertion about a missing
 * relation could ever report, and it is the reason this file asserts the
 * uniqueness is *per campaign* rather than merely that it exists.
 *
 * This is also the only place in the suite where two campaigns hold segments at
 * once, and with one campaign a leak is invisible by construction (L-21) — the
 * shape a value captured once and served to every campaign after produces,
 * which this project has measured five times over: a cached connection, a
 * cached broker, a cached mailer, a rate-limit counter, a lock name.
 *
 * These tests provision their own campaigns rather than using the campaign
 * harness, which keeps one campaign per file inside a transaction that a switch
 * to a second campaign would purge (L-10).
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('each campaign keeps the segments it has named in its own database', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    tenancy()->initialize($harbor);
    Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Dockside streets']);

    tenancy()->end();

    tenancy()->initialize($ridge);
    Segment::factory()->narrowedToPostcodes(['021'])->create(['name' => 'Above the treeline']);

    // The isolation stated as behaviour rather than as a fact about schemas: one
    // identical query, asked in two campaigns, answers only about the campaign
    // asking. Nothing scopes it -- there is no campaign_id to filter on, because
    // the other campaign's segments are in another database entirely.
    expect(Segment::query()->pluck('name')->all())->toBe(['Above the treeline']);

    tenancy()->end();
    tenancy()->initialize($harbor);

    expect(Segment::query()->pluck('name')->all())->toBe(['Dockside streets']);

    // And the segments really are in the campaign's own database rather than in
    // a shared one both campaigns happen to be reading a slice of.
    expect(DB::connection()->getDatabaseName())->toBe($harbor->database()->getName())
        ->and($harbor->database()->getName())->not->toBe($ridge->database()->getName());
});

test('two campaigns may name the same segment, because the name is unique within one campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The claim that survives a pooled table without anything crashing, which is
    // why it is written separately -- and the form of it that is specific to
    // this module.
    //
    // `segments.name` is unique, and the whole question is *unique within
    // what*. Pooled centrally the answer would be "the platform", so the second
    // campaign to aim at a place both are working in would be refused the right
    // to name it -- told, in effect, that a campaign it has never heard of has
    // already used the word. Two campaigns running in two different districts
    // can both have a Culver City, and each owns its own, with its own ZIP codes
    // and its own author.
    tenancy()->initialize($harbor);
    Segment::factory()->narrowedToPostcodes(['9023'])->create(['name' => 'Culver City']);

    tenancy()->end();
    tenancy()->initialize($ridge);

    // Refused under a pooled table; correct here. Written as the first
    // assertion because it is the one the defect reports as an error rather
    // than as a wrong answer.
    $ridgeSegment = Segment::factory()->narrowedToPostcodes(['913', '914'])->create(['name' => 'Culver City']);

    expect($ridgeSegment->exists)->toBeTrue()
        ->and(Segment::query()->count())->toBe(1);

    // The other half of "unique within one campaign", made through the same
    // call in the same run: the constraint is real, and it is real *here*. A
    // table that had simply lost its unique index would satisfy the assertion
    // above just as happily.
    $refusal = null;

    try {
        Segment::factory()->narrowedToPostcodes(['911'])->create(['name' => 'Culver City']);
    } catch (QueryException $caught) {
        $refusal = $caught;
    }

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23505 -- unique violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $refusal->getCode())->toBe('23505');

    tenancy()->end();
    tenancy()->initialize($harbor);

    $harborSegment = Segment::query()->sole();

    // The same name, and nothing else about them shared: two campaigns aiming
    // at two different places that happen to be called the same thing. A
    // central table would hand each campaign both rows, and every assertion
    // about a *missing* table would stay green while it did. Stated as one
    // assertion so a later edit cannot drop half of it.
    expect($harborSegment->name)->toBe($ridgeSegment->name)
        ->and($harborSegment->postcode_prefixes)->toBe(['9023'])
        ->and($ridgeSegment->postcode_prefixes)->toBe(['913', '914']);
});
