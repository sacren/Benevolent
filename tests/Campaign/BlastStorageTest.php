<?php

declare(strict_types=1);

use App\Blasts\BlastStatus;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The matching claim -- that the central database carries no blasts -- lives in
// tests/Feature/CentralSchemaTest.php rather than here, for the reason L-18
// records: this suite rebuilds the central schema only when it is missing, so a
// migration misfiled into the central set is never applied during this run and
// the absence would hold whether or not it is true.
//
// refusalFrom() lives in tests/Pest.php, shared with the supporter storage test.

test('the campaign database carries the blasts the campaign has written', function (): void {
    expect(Schema::hasTable('blasts'))->toBeTrue()
        ->and(Schema::hasColumns('blasts', [
            'operator_id',
            'subject',
            'body',
            'segment_id',
            'postcode_prefixes',
            'status',
            'queued_at',
            'finished_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('a blast written in campaign context lands in the campaign database', function (): void {
    Blast::factory()->create(['subject' => 'Save the harbor']);

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName());

    $this->assertDatabaseHas('blasts', ['subject' => 'Save the harbor'], 'tenant');
});

test('the factory builds a valid blast, and it is a draft addressed to everyone', function (): void {
    $blast = Blast::factory()->create();

    $reloaded = Blast::query()->whereKey($blast->getKey())->sole();

    expect($reloaded->subject)->not->toBeEmpty()
        ->and($reloaded->body)->not->toBeEmpty()
        ->and($reloaded->status)->toBe(BlastStatus::Draft)
        ->and($reloaded->queued_at)->toBeNull()
        ->and($reloaded->finished_at)->toBeNull()
        // Null is the audience rule "everyone this campaign may contact", not
        // an unfinished field. A blast narrowed to nobody would be an empty
        // list, which is a different value and a different meaning.
        ->and($reloaded->postcode_prefixes)->toBeNull();
});

test('the audience is a rule the blast stores, and only the operator-chosen half of it', function (): void {
    // D-14 as data. What a blast holds is criteria, evaluated when sending
    // starts -- so somebody who unsubscribes after this row is written is left
    // out of the send, which a frozen recipient list could not manage.
    //
    // Written as an operator would type them, unevenly, because the column they
    // will be matched against holds postcodes exactly as their source gave them.
    $blast = Blast::factory()->narrowedToPostcodes(['902', '6060'])->create();

    $reloaded = Blast::query()->whereKey($blast->getKey())->sole();

    expect($reloaded->postcode_prefixes)->toBe(['902', '6060']);

    // The half that is deliberately absent, asserted as an absence because its
    // absence is the guarantee. Subscribed-only is the condition the product
    // enforces rather than one an operator chooses, so there is no column here
    // that could record an intention to reach people who asked not to be
    // contacted -- and therefore no way for one to be set.
    expect(Schema::getColumnListing('blasts'))
        ->not->toContain('subscription_status')
        ->not->toContain('include_unsubscribed')
        // Paired with the positive claim through the same call in the same run
        // (L-19): a listing that returned nothing at all would satisfy every
        // line above on its own.
        ->toContain('postcode_prefixes');
});

test('a blast records who wrote it, and keeps the record when they leave', function (): void {
    $author = User::factory()->create();
    $blast = Blast::factory()->writtenBy($author)->create();

    expect(Blast::query()->whereKey($blast->getKey())->sole()->operator_id)
        ->toBe($author->getKey());

    $author->delete();

    // The record of what a campaign sent outlives whoever sent it. A cascade
    // here would erase the campaign's own account of a message that is already
    // in other people's inboxes, and deleting the row would not recall it.
    $reloaded = Blast::query()->whereKey($blast->getKey())->sole();

    expect($reloaded->exists)->toBeTrue()
        ->and($reloaded->operator_id)->toBeNull();
});

test('the column defaults to a draft for a row that names no status', function (): void {
    // Written straight to the table, bypassing Eloquent and the factory, so the
    // value under test can only have come from the database.
    //
    // This is also what keeps the migration frozen. It hardcodes 'draft' rather
    // than reading BlastStatus::default(), because it re-runs for every campaign
    // at whatever date that campaign is provisioned, and a default read out of
    // application code would give campaigns created after an edit a different
    // schema from the ones already provisioned. The cost of hardcoding is that
    // two places must agree; this is what enforces it.
    DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'Raw insert',
        'body' => 'Written past the model.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stored = DB::connection('tenant')->table('blasts')
        ->where('subject', 'Raw insert')
        ->value('status');

    // Pinned to each other, so the migration's literal cannot drift from the
    // enum in either direction...
    expect($stored)->toBe(BlastStatus::default()->value);

    // ...and pinned to the intended choice, so the pair cannot move together
    // and stay green. A blast that arrived in any other state would be one the
    // campaign had committed without ever saying so.
    expect(BlastStatus::default())->toBe(BlastStatus::Draft);
});

test('the database refuses a draft that has been committed to sending', function (): void {
    // The irreversibility, as a fact about the row rather than a convention the
    // application remembers. A draft is editable and sendable; a blast the
    // campaign has let go is neither. A row claiming both would make an already
    // committed message look like one still safe to change.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'Committed, and still calling itself a draft',
        'body' => 'Refused.',
        'status' => 'draft',
        'queued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23514 -- check violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $refusal->getCode())->toBe('23514');

    // The positive half, made through the same call in the same run: without it
    // this passes just as happily against a table that refuses every insert.
    $legitimate = Blast::factory()->queued()->create();

    expect($legitimate->exists)->toBeTrue()
        ->and(Blast::query()->count())->toBe(1);
});

test('the database refuses a blast that has left draft without recording when', function (): void {
    // The other direction, and it is a real mistake rather than the same one
    // written backwards: a send that sets the status and forgets the timestamp
    // leaves a campaign unable to say when it committed -- which, for the one
    // act this product cannot undo, is the same as not knowing whether it did.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'Sending, with no record of when it started',
        'body' => 'Refused.',
        'status' => 'sending',
        'queued_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23514');

    $legitimate = Blast::factory()->sending()->create();

    expect($legitimate->exists)->toBeTrue()
        ->and(Blast::query()->count())->toBe(1);
});

test('a blast that has left draft can never be a draft again', function (): void {
    $blast = Blast::factory()->sent()->create();

    // Walking the status back on its own is refused by the database, so the
    // half-fix -- the one somebody writes when they mean to let an operator
    // "edit and resend" -- cannot land at all.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')
        ->where('id', $blast->getKey())
        ->update(['status' => 'draft']));

    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23514');

    $reloaded = Blast::query()->whereKey($blast->getKey())->sole();

    expect($reloaded->status)->toBe(BlastStatus::Sent)
        ->and($reloaded->queued_at)->not->toBeNull();

    // Said at its true strength, because the weaker claim is the true one: this
    // constraint makes the two states mutually exclusive, not the row frozen.
    // Clearing both columns together is still a legal write, and nothing in the
    // database stops it -- what is gone is every way of arriving there by
    // accident, one column at a time.
    DB::connection('tenant')->table('blasts')
        ->where('id', $blast->getKey())
        ->update(['status' => 'draft', 'queued_at' => null]);

    expect(Blast::query()->whereKey($blast->getKey())->sole()->status)
        ->toBe(BlastStatus::Draft);
});

test('the status round-trips through the database as an enum', function (): void {
    $blast = Blast::factory()->failed()->create();

    $reloaded = Blast::query()->whereKey($blast->getKey())->sole();

    expect($reloaded->status)->toBe(BlastStatus::Failed)
        ->and($reloaded->status->isFinished())->toBeTrue()
        ->and($reloaded->status->isCommitted())->toBeTrue()
        // And the stored value is the enum's own, so the column and the
        // vocabulary cannot drift into two spellings of one state.
        ->and(DB::connection('tenant')->table('blasts')->where('id', $blast->getKey())->value('status'))
        ->toBe(BlastStatus::Failed->value);
});

test('a draft is the only state a blast is not yet committed from', function (): void {
    // The negative of Draft rather than a list of the other four, so a case
    // added later is committed unless somebody remembers otherwise -- which is
    // the safe direction for a question about whether a message can still be
    // recalled.
    $committed = array_filter(BlastStatus::cases(), fn (BlastStatus $s): bool => $s->isCommitted());

    expect(BlastStatus::Draft->isCommitted())->toBeFalse()
        ->and($committed)->toBe(array_filter(
            BlastStatus::cases(),
            fn (BlastStatus $s): bool => $s !== BlastStatus::Draft,
        ));
});

test('a blast can be aimed at a segment the campaign has named', function (): void {
    // D-26 as data, and the shape of the answer is that both columns are here:
    // a blast points at a segment *or* carries its own rule, so the pointer was
    // added without taking the rule away.
    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    $reloaded = Blast::query()->whereKey($blast->getKey())->sole();

    expect($reloaded->segment_id)->toBe($segment->getKey())
        ->and($reloaded->postcode_prefixes)->toBeNull()
        // The relation resolves to the campaign's own row, and its prefixes are
        // the ones the segment holds rather than a copy taken at any point.
        // Copying would be the shape §7 names as an illegitimate way to satisfy
        // this phase's fifth criterion.
        ->and($reloaded->segment?->getKey())->toBe($segment->getKey())
        ->and($reloaded->segment?->postcode_prefixes)->toBe(['902']);
});

test('the database refuses a blast that names two aims at once', function (): void {
    $segment = Segment::factory()->create();

    // An aim is one thing. The alternative to this constraint was precedence in
    // application code -- "the segment wins" -- and the reason it was refused is
    // that a rule about which of two columns to believe is a rule some later
    // reader gets wrong, and what they get wrong is who a message goes to.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'Aimed two ways',
        'body' => 'Refused.',
        'segment_id' => $segment->getKey(),
        'postcode_prefixes' => json_encode(['902']),
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23514 -- check violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $refusal->getCode())->toBe('23514');

    // The three legitimate shapes, made through the same table in the same run:
    // without them this passes just as happily against a table that refuses
    // every insert (L-19).
    $pointing = Blast::factory()->aimedAtSegment($segment)->create();
    $carrying = Blast::factory()->narrowedToPostcodes(['902'])->create();

    // Neither column set, which is the ordinary blast to everybody the campaign
    // may contact and is this module's only widening branch. It is named here
    // because a constraint written as "exactly one of the two" would forbid it,
    // and that is the plausible wrong version of this rule.
    $everyone = Blast::factory()->create();

    expect($pointing->exists)->toBeTrue()
        ->and($carrying->exists)->toBeTrue()
        ->and($everyone->exists)->toBeTrue()
        ->and($everyone->segment_id)->toBeNull()
        ->and($everyone->postcode_prefixes)->toBeNull()
        ->and(Blast::query()->count())->toBe(3);
});

test('the database refuses to delete a segment a blast is aimed at', function (): void {
    $segment = Segment::factory()->create();

    Blast::factory()->aimedAtSegment($segment)->create();

    // **Restricted rather than nulled, and this is the assertion that matters
    // most in this file.** Both of this table's other foreign keys are
    // nullOnDelete, and copying that here would be wrong in a way nothing would
    // report: a null aim is this module's *widening* branch, so deleting the
    // segment would silently turn a blast aimed at one postcode into one aimed
    // at every supporter the campaign may contact.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('segments')
        ->where('id', $segment->getKey())
        ->delete());

    expect($refusal)->not->toBeNull()
        // **SQLSTATE 23001 -- restrict_violation, and measured rather than
        // assumed.** The obvious guess is 23503, foreign_key_violation, which
        // is what PostgreSQL raises for the schema builder's *default* on-delete
        // behaviour (NO ACTION). ON DELETE RESTRICT is a stricter rule -- it
        // cannot be deferred to the end of the transaction -- and it reports
        // itself with its own code. Either way it is a different code from the
        // check violations above, so this cannot be satisfied by whatever
        // reddens those.
        ->and((string) $refusal->getCode())->toBe('23001');

    // And the widening that would have happened did not: the blast still points
    // where it did, which is the property the code is standing in for.
    expect(Blast::query()->sole()->segment_id)->toBe($segment->getKey())
        ->and(Segment::query()->count())->toBe(1);
});

test('a segment nothing points at is still deletable', function (): void {
    // The control for the refusal above. Without it that test passes against a
    // table no segment can ever be deleted from, which would be a different and
    // worse product.
    $segment = Segment::factory()->create();

    $segment->delete();

    expect(Segment::query()->count())->toBe(0);
});

test('the database refuses a draft that already carries a frozen aim', function (): void {
    // **The direction that reads as harmless and is not.** A frozen rule on a
    // draft is an aim recorded before the campaign committed to one, and the
    // frozen rule is what a committed blast's audience is read from -- so a
    // draft carrying one would quietly stop following the segment its operator
    // is still editing. The pointer would still be on the row, the page would
    // still name the segment, and the audience would be somebody else's.
    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'A draft that has already made up its mind',
        'body' => 'Refused.',
        'segment_id' => $segment->getKey(),
        'committed_prefixes' => json_encode(['902']),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        // SQLSTATE 23514 -- check violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $refusal->getCode())->toBe('23514');

    // The positive half, made through the same table in the same run: without
    // it this passes just as happily against a table that refuses every insert.
    $draft = Blast::factory()->aimedAtSegment($segment)->create();

    expect($draft->exists)->toBeTrue()
        ->and($draft->committed_prefixes)->toBeNull()
        ->and(Blast::query()->count())->toBe(1);
});

test('the database refuses a committed segment-aimed blast with no frozen aim', function (): void {
    // **The other direction, and it is the defect the column exists to
    // prevent** rather than the same mistake written backwards: a commit that
    // forgot to freeze. There would be nothing for a committed blast's audience
    // to be read from, and the only safe reading of nothing is that the blast
    // reaches nobody -- a message the campaign committed and that silently
    // never goes.
    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'Committed without freezing what it was aimed at',
        'body' => 'Refused.',
        'segment_id' => $segment->getKey(),
        'committed_prefixes' => null,
        'status' => 'queued',
        'queued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23514');

    $committed = Blast::factory()->aimedAtSegment($segment)->queued()->create();

    expect($committed->exists)->toBeTrue()
        ->and($committed->committed_prefixes)->toBe(['902'])
        ->and(Blast::query()->count())->toBe(1);
});

test('a committed blast that never pointed at a segment carries no frozen aim', function (): void {
    // The rows the constraint must leave alone, named because a constraint
    // written as "every committed blast has a frozen aim" would forbid both of
    // them -- and that is the plausible wrong version of this rule, the same
    // way "exactly one of the two" was for the aim itself.
    //
    // Neither needs freezing. A blast carrying its own prefixes has its rule on
    // its own row, which nothing but its own compose form can reach and which
    // refuseCommitted() closes the moment it leaves draft; a blast aimed at
    // nobody in particular has no rule at all.
    $carrying = Blast::factory()->narrowedToPostcodes(['902'])->queued()->create();
    $everyone = Blast::factory()->queued()->create();

    expect($carrying->committed_prefixes)->toBeNull()
        ->and($carrying->postcode_prefixes)->toBe(['902'])
        ->and($everyone->committed_prefixes)->toBeNull()
        ->and($everyone->segment_id)->toBeNull()
        ->and($everyone->postcode_prefixes)->toBeNull();
});

test('the frozen aim round-trips through the database as a list', function (): void {
    // The cast, pinned the way the status enum's is. A json column read back as
    // a string rather than a list would reach PostcodeNarrowing::apply() as
    // something it cannot fold, and the fail-closed branch would turn a
    // committed send into a send to nobody.
    $segment = Segment::factory()->narrowedToPostcodes(['902', '911 0'])->create();

    $blast = Blast::factory()->aimedAtSegment($segment)->sent()->create();

    expect(Blast::query()->whereKey($blast->getKey())->sole()->committed_prefixes)
        ->toBe(['902', '911 0']);
});

test('a committed blast aimed at a district freezes ZIP codes rather than prefixes', function (): void {
    // **The row this column exists for (D-38).** A segment that narrows by seat
    // has no prefixes to freeze, and freezing `MA-07` itself would freeze a
    // name whose meaning ships with the release -- so what is recorded is the
    // ZIP codes the relation claimed at the moment the campaign committed.
    //
    // Written through the table rather than through the factory because
    // nothing in the application writes this column yet: the statement that
    // fills it and the reader that prefers it are later commits, and this is
    // the place they will land.
    $segment = Segment::factory()->inDistrict('MA-07')->create();

    DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'To everyone we can place in MA-07',
        'body' => 'Committed.',
        'segment_id' => $segment->getKey(),
        'committed_prefixes' => null,
        'committed_zip_codes' => json_encode(['02141', '02115']),
        'status' => 'queued',
        'queued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $blast = Blast::query()->sole();

    // The cast, pinned the way the prefixes one is: a json column read back as
    // a string would reach DistrictNarrowing::toZipCodes() as something its
    // five-digit check refuses, and the fail-closed branch would turn a
    // committed send into a send to nobody.
    expect($blast->committed_zip_codes)->toBe(['02141', '02115'])
        ->and($blast->committed_prefixes)->toBeNull();
});

test('the database refuses a blast frozen two ways at once', function (): void {
    // **Which column a frozen rule sits in is what says how to replay it**, so
    // a row holding both says two things at once: PostcodeNarrowing reaches a
    // stored `02141abc` through `02141` and DistrictNarrowing does not, and a
    // send choosing between them would be choosing who the message goes to.
    //
    // **The draft half is not the same mistake written twice.** It is the row
    // the obvious amendment admits: written as `(num_nonnulls(...) = 1) =
    // (committed and segment-aimed)`, a draft carrying both passes, because two
    // is not one and a draft is not committed, and false equals false. The
    // constraint counts instead, so both rows below are refused.
    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $frozenRow = fn (string $status, ?string $queuedAt): array => [
        'subject' => 'Frozen two ways',
        'body' => 'Refused.',
        'segment_id' => $segment->getKey(),
        'committed_prefixes' => json_encode(['902']),
        'committed_zip_codes' => json_encode(['02141']),
        'status' => $status,
        'queued_at' => $queuedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $committed = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert(
        $frozenRow('queued', (string) now())
    ));

    $draft = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert(
        $frozenRow('draft', null)
    ));

    expect($committed)->not->toBeNull()
        // SQLSTATE 23514 -- check violation. Asserted by code rather than by
        // message so a reworded or translated error cannot weaken this.
        ->and((string) $committed->getCode())->toBe('23514')
        ->and($draft)->not->toBeNull()
        ->and((string) $draft->getCode())->toBe('23514');

    // The positive half, through the same table in the same run: without it
    // both refusals above pass just as happily against a table that refuses
    // every insert.
    $committing = Blast::factory()->aimedAtSegment($segment)->queued()->create();

    expect($committing->committed_prefixes)->toBe(['902'])
        ->and($committing->committed_zip_codes)->toBeNull()
        ->and(Blast::query()->count())->toBe(1);
});

test('the database refuses a draft that already carries frozen ZIP codes', function (): void {
    // The district twin of the refusal one screen up, and it fails the same
    // way: a draft that has already recorded an aim has stopped following the
    // segment its operator is still editing, while the page goes on naming the
    // segment.
    $segment = Segment::factory()->inDistrict('MA-07')->create();

    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'A draft that has already made up its mind',
        'body' => 'Refused.',
        'segment_id' => $segment->getKey(),
        'committed_zip_codes' => json_encode(['02141']),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23514');

    // A draft may point at a district segment -- the database has never said
    // otherwise, and what stops a blast being aimed that way today is the
    // compose form. What it may not do is carry the record of a commitment it
    // has not made.
    $draft = Blast::factory()->aimedAtSegment($segment)->create();

    expect($draft->exists)->toBeTrue()
        ->and($draft->committed_zip_codes)->toBeNull()
        ->and(Blast::query()->count())->toBe(1);
});

test('a blast that never pointed at a segment cannot freeze ZIP codes either', function (): void {
    // The rows the constraint must go on leaving alone, asserted against the
    // new column rather than assumed from the old one. A blast carrying its own
    // prefixes and a blast aimed at everybody have nothing to freeze, and a
    // frozen value on either would be a record of a commitment to an aim
    // neither of them made.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('blasts')->insert([
        'subject' => 'Frozen without ever pointing anywhere',
        'body' => 'Refused.',
        'segment_id' => null,
        'committed_zip_codes' => json_encode(['02141']),
        'status' => 'queued',
        'queued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23514');

    $carrying = Blast::factory()->narrowedToPostcodes(['902'])->queued()->create();
    $everyone = Blast::factory()->queued()->create();

    expect($carrying->committed_zip_codes)->toBeNull()
        ->and($everyone->committed_zip_codes)->toBeNull()
        ->and(Blast::query()->count())->toBe(2);
});
