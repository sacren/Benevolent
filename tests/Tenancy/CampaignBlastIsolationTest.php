<?php

declare(strict_types=1);

use App\Blasts\BlastStatus;
use App\Models\Blast;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The DEC-1 isolation guarantee, restated for the first thing this platform
 * does that leaves it.
 *
 * CampaignIsolationTest proves a campaign cannot read another campaign's
 * operators; CampaignSupporterIsolationTest proves it for the people a campaign
 * is trying to reach. This proves it for what a campaign *says* to them —
 * which is the one a reader would most reasonably guess is platform data,
 * because "sent mail" sounds like infrastructure and because the `jobs` and
 * `failed_jobs` tables genuinely are central. Nothing enforces it separately:
 * it falls out of `App\Models\Blast` naming no connection, so the model follows
 * the default one tenancy has switched onto the campaign serving the request.
 *
 * **What this file is worth was measured rather than claimed, and it is not the
 * last line of defence.** With this file removed and the pooled-blasts defect
 * rebuilt the way somebody who believed sent mail were platform data would
 * actually ship it — the model naming the central connection, with the
 * migration filed into the central set so there is a table for it to reach —
 * **ten other tests still report it**: seven failures and three errors, in a
 * suite of 286. That is almost exactly what the same audit found for the
 * supporter isolation file, and it is recorded here for the same reason: a
 * guard whose docblock claims more than it does is believed without being
 * checked, which is the failure mode of a guard that cannot fail wearing
 * better clothes. (Naming the connection *alone* reddens for the wrong
 * reason: `Database connection [central] not configured`, which is a typo
 * rather than the hazard. Phase 1 recorded that trap and it applies here
 * unchanged.)
 *
 * Two things it carries that nothing else does. Only the second test states
 * that a blast's *identity is campaign-local* — two campaigns writing about the
 * same thing, on the same day, each holding their own message — which a pooled
 * table would answer wrongly as a design error rather than as a crash, so every
 * test that reddens on a missing relation stays green against it. And this is
 * the only place in the suite where two campaigns hold blasts at once; with one
 * campaign a leak is invisible by construction (L-21) — the shape a value
 * captured once and served to every campaign after produces, which this project
 * has measured five times over: a cached connection, a cached broker, a cached
 * mailer, a rate-limit counter, a lock name. The count is enumerated rather
 * than asserted because the bare number is already spelt three different ways
 * in this suite, and a number nothing derives is a claim nothing checks.
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

test('each campaign keeps the messages it has written in its own database', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    tenancy()->initialize($harbor);
    Blast::factory()->create(['subject' => 'Dredging starts Monday']);

    tenancy()->end();

    tenancy()->initialize($ridge);
    Blast::factory()->create(['subject' => 'The ridge path reopens']);

    // The isolation stated as behaviour rather than as a fact about schemas: one
    // identical query, asked in two campaigns, answers only about the campaign
    // asking. Nothing scopes it -- there is no campaign_id to filter on, because
    // the other campaign's messages are in another database entirely.
    expect(Blast::query()->pluck('subject')->all())->toBe(['The ridge path reopens']);

    tenancy()->end();
    tenancy()->initialize($harbor);

    expect(Blast::query()->pluck('subject')->all())->toBe(['Dredging starts Monday']);

    // And the messages really are in the campaign's own database rather than in
    // a shared one both campaigns happen to be reading a slice of.
    expect(DB::connection()->getDatabaseName())->toBe($harbor->database()->getName())
        ->and($harbor->database()->getName())->not->toBe($ridge->database()->getName());
});

test('two campaigns may write the same message, because a blast belongs to one campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The claim that survives a pooled table without crashing, which is why it
    // is written separately. Two campaigns campaigning on the same issue write
    // the same subject line on the same day, and each owns its own message --
    // its own audience rule, its own status, its own moment of committing to
    // send. A central table would hand each campaign both, and every assertion
    // about a *missing* table would stay green while it did.
    tenancy()->initialize($harbor);
    Blast::factory()->narrowedToPostcodes(['M15'])->create(['subject' => 'Object before Friday']);

    tenancy()->end();
    tenancy()->initialize($ridge);
    Blast::factory()->sent()->create(['subject' => 'Object before Friday']);

    $ridgeBlast = Blast::query()->sole();

    expect($ridgeBlast->status)->toBe(BlastStatus::Sent)
        ->and($ridgeBlast->postcode_prefixes)->toBeNull();

    tenancy()->end();
    tenancy()->initialize($harbor);

    $harborBlast = Blast::query()->sole();

    // The same subject, and nothing else about them shared: one campaign has
    // already sent to everybody, the other still holds a draft aimed at one
    // postcode. Stated as one assertion so a later edit cannot drop half of it.
    expect($harborBlast->subject)->toBe($ridgeBlast->subject)
        ->and($harborBlast->status)->toBe(BlastStatus::Draft)
        ->and($harborBlast->postcode_prefixes)->toBe(['M15'])
        ->and($harborBlast->queued_at)->toBeNull();
});

test('the constraint that keeps a sent blast sent is created in every campaign, not just the first', function (): void {
    // A raw-DDL statement in a migration is the kind that reaches one database
    // and quietly not the next: it runs through whatever connection was current
    // when it executed, and under database-per-tenant that is a moving target.
    // The supporter table's unique index has the same exposure, and both are
    // written through the schema builder's own connection for this reason.
    //
    // Asked of the *second* campaign as well as the first, because that is the
    // whole distinction (L-21) -- a statement landing centrally, or on whichever
    // database happened to be open, would leave this green for one campaign and
    // false for every campaign after it.
    foreach (['harbor-cleanup', 'ridge-restoration'] as $slug) {
        $campaign = Tenant::query()->where('slug', $slug)->firstOrFail();

        tenancy()->end();
        tenancy()->initialize($campaign);

        $constraints = collect(DB::select(
            "select conname from pg_constraint where conrelid = 'blasts'::regclass and contype = 'c'"
        ))->pluck('conname');

        expect($constraints)->toContain('blasts_draft_has_not_been_queued');

        // The configuration invariant paired with the behaviour it produces
        // (L-14), made in the same run so neither half can stand alone: the
        // constraint is present *and* it refuses.
        $refusal = null;

        try {
            DB::table('blasts')->insert([
                'subject' => 'Committed, and still calling itself a draft',
                'body' => 'Refused.',
                'status' => 'draft',
                'queued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $caught) {
            $refusal = $caught;
        }

        expect($refusal)->not->toBeNull()
            ->and((string) $refusal->getCode())->toBe('23514');
    }
});
