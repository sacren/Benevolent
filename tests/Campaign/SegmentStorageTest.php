<?php

declare(strict_types=1);

use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The matching claim -- that the central database carries no segments -- lives
// in tests/Feature/CentralSchemaTest.php rather than here, for the reason L-18
// records: this suite rebuilds the central schema only when it is missing, so a
// migration misfiled into the central set is never applied during this run and
// the absence would hold whether or not it is true.
//
// refusalFrom() lives in tests/Pest.php, shared with the supporter and blast
// storage tests.

test('the campaign database carries the segments the campaign has named', function (): void {
    expect(Schema::hasTable('segments'))->toBeTrue()
        ->and(Schema::hasColumns('segments', [
            'operator_id',
            'name',
            'postcode_prefixes',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('a segment named in campaign context lands in the campaign database', function (): void {
    Segment::factory()->create(['name' => 'Harbor precinct']);

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName());

    $this->assertDatabaseHas('segments', ['name' => 'Harbor precinct'], 'tenant');
});

test('the factory builds a valid segment, and it narrows to something', function (): void {
    $segment = Segment::factory()->create();

    $reloaded = Segment::query()->whereKey($segment->getKey())->sole();

    expect($reloaded->name)->not->toBeEmpty()
        // Not merely non-null. `postcode_prefixes` is NOT NULL here where the
        // same column on `blasts` is nullable, because null there means "every
        // supporter this campaign may contact" and a segment that narrows
        // nothing is not a segment. A factory defaulting to an empty list would
        // satisfy the column and produce exactly the row the column shape was
        // chosen to make meaningless.
        ->and($reloaded->postcode_prefixes)->toBe(['902']);
});

test('the rule a segment stores is postcodes, and only postcodes', function (): void {
    // D-24 as data, and stated as an absence because the absence is the
    // guarantee.
    //
    // Written as an operator would type them, unevenly, because the column they
    // will be matched against holds postcodes exactly as their source gave them.
    $segment = Segment::factory()->narrowedToPostcodes(['902', '6060'])->create();

    expect(Segment::query()->whereKey($segment->getKey())->sole()->postcode_prefixes)
        ->toBe(['902', '6060']);

    $columns = Schema::getColumnListing('segments');

    // **No subscription predicate, and no column that could become one.** The
    // supporter list may legitimately show people who unsubscribed; a send may
    // never reach them, and App\Blasts\BlastAudience enforces that by its shape
    // rather than by a parameter. A stored rule carrying a status would
    // therefore mean two different things to its two readers, and would be one
    // refactor from a message reaching somebody who asked not to be contacted.
    //
    // This is the storage half of that guarantee and it is the only half this
    // step can make: nothing here connects a segment to an audience yet, so the
    // claim proven is that the rule *cannot say* anything about subscription,
    // not that a send would ignore it if it did. The second half arrives with
    // the step that lets a blast point at a segment.
    expect($columns)
        ->not->toContain('subscription_status')
        ->not->toContain('include_unsubscribed')
        // **And no string a campaign typed about a person.** Searching is
        // transient and may touch a name; segmenting is stored and may not,
        // because SupporterController::destroy() deletes a row and nothing
        // reaches a rule that outlived it.
        ->not->toContain('name_contains')
        ->not->toContain('email_contains')
        // Paired with the positive claim through the same call in the same run
        // (L-19): a listing that returned nothing at all would satisfy every
        // line above on its own.
        ->toContain('postcode_prefixes');
});

test('a segment records who named it, and keeps the record when they leave', function (): void {
    $author = User::factory()->create();
    $segment = Segment::factory()->namedBy($author)->create();

    expect(Segment::query()->whereKey($segment->getKey())->sole()->operator_id)
        ->toBe($author->getKey());

    $author->delete();

    // A segment is a shared object other operators' work is meant to point at,
    // so it has to outlive whoever created it. A cascade here would take the
    // aim of every message built on it away with the person who left.
    $reloaded = Segment::query()->whereKey($segment->getKey())->sole();

    expect($reloaded->exists)->toBeTrue()
        ->and($reloaded->operator_id)->toBeNull();
});

test('the database refuses two segments with the same name', function (): void {
    Segment::factory()->create(['name' => 'Culver City']);

    // The failure this prevents is aiming at the wrong group: two segments
    // called the same thing are indistinguishable at the moment somebody picks
    // one, and the message has gone by the time anybody works out which was
    // picked.
    $refusal = refusalFrom(fn () => Segment::factory()->create(['name' => 'Culver City']));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23505 -- unique violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $refusal->getCode())->toBe('23505');

    // The positive half, made through the same call in the same run: without it
    // this passes just as happily against a table that refuses every insert.
    $second = Segment::factory()->create(['name' => 'Beverly Hills']);

    expect($second->exists)->toBeTrue()
        ->and(Segment::query()->count())->toBe(2);
});

test('the uniqueness is on the name exactly, and case variants are two segments', function (): void {
    // The deliberate difference from `supporters`, pinned so that it is a
    // recorded decision rather than something a later reader repairs.
    //
    // D-8 gave `supporters` a unique index on lower(email) -- and this
    // project's first raw DDL with it -- because case variation is the
    // commonest variation in imported addresses and the duplicate it admits is
    // silent: nobody reads a supporter list looking for near-duplicates. A
    // segment name is typed by one of a campaign's handful of operators, and
    // both rows appear in the list a person is reading at the moment they
    // choose one. That duplicate is visible and correctable.
    //
    // If a later step decides otherwise, this is the line that goes red and
    // says where the decision was made.
    Segment::factory()->create(['name' => 'Culver City']);

    $variant = Segment::factory()->create(['name' => 'culver city']);

    expect($variant->exists)->toBeTrue()
        ->and(Segment::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Culver City', 'culver city']);
});

test('the database refuses a segment that narrows nothing at all', function (): void {
    // NOT NULL is where this column parts company with the one on `blasts`.
    // There, null is the audience rule "everybody this campaign may contact" --
    // the one branch in BlastAudience that widens rather than narrows -- and it
    // is right there. Here it would be a segment with no meaning, and refusing
    // it means the widening branch cannot be reached through a segment at all.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('segments')->insert([
        'name' => 'Narrows nothing',
        'postcode_prefixes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23502 -- not-null violation.
        ->and((string) $refusal->getCode())->toBe('23502');

    // The positive half through the same call in the same run.
    $legitimate = Segment::factory()->create();

    expect($legitimate->exists)->toBeTrue()
        ->and(Segment::query()->count())->toBe(1);
});

test('an empty rule is stored rather than refused, and the schema says so deliberately', function (): void {
    // The absent check constraint, asserted rather than left as a silence.
    //
    // `blasts` carries a raw-DDL check constraint, and it earned it with a
    // measurement: a blast which has merely been queued is already unrecallable,
    // because a worker may claim it at any instant, so the irreversibility had
    // to be a fact about the row. A segment has no unrecallable act -- nothing
    // leaves the platform when one is saved -- so the corresponding constraint
    // here would be the mechanism taken without the measurement that bought it.
    //
    // An empty rule is safe rather than dangerous: a rule naming nothing usable
    // already matches nobody under the fail-closed reading BlastAudience
    // established, so this is a useless segment rather than a widening one.
    // Keeping it out of the schema is what leaves the check to the form that
    // will take the operator's input.
    $empty = Segment::factory()->narrowedToPostcodes([])->create();

    expect(Segment::query()->whereKey($empty->getKey())->sole()->postcode_prefixes)
        ->toBe([]);

    $constraints = collect(DB::select(
        "select conname from pg_constraint where conrelid = 'segments'::regclass and contype = 'c'"
    ))->pluck('conname');

    expect($constraints)->toBeEmpty();
});
