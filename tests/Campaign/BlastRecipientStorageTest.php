<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Supporter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// The matching claim -- that the central database carries no blast recipients --
// lives in tests/Feature/CentralSchemaTest.php rather than here, for the reason
// L-18 records: this suite rebuilds the central schema only when it is missing,
// so a migration misfiled into the central set is never applied during this run
// and the absence would hold whether or not it is true.
//
// refusalFrom() lives in tests/Pest.php, shared with the two storage tests that
// already need a refusal rolled back to a savepoint.

test('the campaign database carries who a blast was written to', function (): void {
    expect(Schema::hasTable('blast_recipients'))->toBeTrue()
        ->and(Schema::hasColumns('blast_recipients', [
            'blast_id',
            'supporter_id',
            'sent_at',
            'failure_reason',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('a recipient row holds no part of the person it names', function (): void {
    // **D-10's question asked against a new table, answered as a column list
    // rather than as a promise.** The resolution that an erasure reaches
    // `supporters` and nothing else is honest only while no other table is a
    // second copy of the same people. Written as an exhaustive comparison
    // rather than a handful of `not->toContain` assertions, because those pass
    // for every column nobody thought to name -- and the column somebody adds
    // later without thinking is exactly the one that would break D-10.
    expect(Schema::getColumnListing('blast_recipients'))->toBe([
        'id',
        'blast_id',
        'supporter_id',
        'sent_at',
        'failure_reason',
        'created_at',
        'updated_at',
        'link_token',
    ]);
});

test('a copy of a message names itself, and stops naming anybody when its reader is erased', function (): void {
    // **`link_token` is the one column here that is not a fact about the send
    // (D-46), so it is the one that has to answer D-10 for itself.** It is a
    // credential rather than a name or an address, and it resolves to a person
    // while it exists -- Step 2 measured it to be a join key back to an
    // address in copies outside the schema, because the request log holds it
    // with a timestamp and the `log` mailer writes it beside the address it
    // mailed. So a token that outlived its supporter would undo the nulling
    // that keeps this table honest.
    $supporter = Supporter::factory()->create();
    $recipient = BlastRecipient::factory()->forSupporter($supporter)->sent()->create();

    // Minted by the column rather than by whoever wrote the row, which is what
    // makes it true of every writer -- the same reason the supporter's own
    // token is a default (D-16(a)).
    $token = DB::connection('tenant')->table('blast_recipients')->where('id', $recipient->getKey())->value('link_token');

    expect($token)->not->toBeEmpty();

    $supporter->delete();

    $row = DB::connection('tenant')->table('blast_recipients')->where('id', $recipient->getKey())->first();

    // The row stays and the count stays honest; what goes is everything that
    // could say whose copy it was.
    expect($row)->not->toBeNull()
        ->and($row->supporter_id)->toBeNull()
        ->and($row->link_token)->toBeNull()
        ->and($row->sent_at)->not->toBeNull();
});

test('a copy claimed before per-recipient links carries no token its message never held', function (): void {
    // **The property the migration is split in two statements for, and the
    // only test that can see it.** Every campaign in this suite is migrated
    // before it holds a row, so a default that arrives with the column and one
    // that arrives after it are indistinguishable here -- which is exactly how
    // this went unguarded until the break for it came back green. So the
    // migration is re-run against a row that predates it: the column and its
    // trigger are taken off, a claim is written the way one was written before
    // this step, and the migration's own up() is what puts them back.
    //
    // Measured on PostgreSQL 18.1: a nullable column added *with* a volatile
    // default fills every existing row. Those rows record messages whose
    // inboxes hold only the old link, so a token on them would make "non-null"
    // false as "this copy carried a per-recipient link" -- the reading the
    // surface needs to tell *not recorded* from *none* (D-49).
    DB::connection('tenant')->statement('drop trigger "blast_recipients_forget_link_token" on "blast_recipients"');
    DB::connection('tenant')->statement('drop function "blast_recipients_forget_link_token"()');
    DB::connection('tenant')->statement('alter table "blast_recipients" drop column "link_token"');

    $blast = Blast::factory()->create();
    $supporter = Supporter::factory()->create();

    DB::connection('tenant')->table('blast_recipients')->insert([
        'blast_id' => $blast->getKey(),
        'supporter_id' => $supporter->getKey(),
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require Arr::first(File::glob(database_path('migrations/tenant/*_add_link_token_to_blast_recipients_table.php')));
    $migration->up();

    // The copy that predates the column says so by holding nothing.
    expect(DB::connection('tenant')->table('blast_recipients')->value('link_token'))->toBeNull();

    // And the column really is working for rows written afterwards, so the
    // null above is the migration's choice rather than a default that never
    // arrived.
    $later = BlastRecipient::factory()->sent()->create();

    expect(DB::connection('tenant')->table('blast_recipients')->where('id', $later->getKey())->value('link_token'))
        ->not->toBeEmpty();
});

test('a row written with no supporter cannot carry a token either', function (): void {
    // The same rule asked of an insert rather than an erasure. The trigger
    // that removes the token fires on both, and the reason it must is that a
    // trigger written for the erasure alone leaves the forbidden state one
    // hand-written insert away -- measured: such a row still took the column
    // default. Nothing in the application writes one, which is exactly why the
    // schema rather than the sending path is what refuses it.
    $blast = Blast::factory()->create();

    DB::connection('tenant')->table('blast_recipients')->insert([
        'blast_id' => $blast->getKey(),
        'supporter_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::connection('tenant')->table('blast_recipients')->whereNull('supporter_id')->value('link_token'))->toBeNull();
});

test('a recipient written in campaign context lands in the campaign database', function (): void {
    $recipient = BlastRecipient::factory()->create();

    expect(DB::connection()->getDatabaseName())
        ->toBe($this->campaign->database()->getName());

    $this->assertDatabaseHas('blast_recipients', ['id' => $recipient->getKey()], 'tenant');
});

test('the factory builds a claim rather than a delivery', function (): void {
    $recipient = BlastRecipient::factory()->create();

    $reloaded = BlastRecipient::query()->whereKey($recipient->getKey())->sole();

    // Neither sent nor failed: the state a recipient is in between being
    // claimed and the message being handed over, and the one a send that stops
    // part-way leaves behind.
    expect($reloaded->sent_at)->toBeNull()
        ->and($reloaded->failure_reason)->toBeNull()
        ->and($reloaded->blast_id)->not->toBeNull()
        ->and($reloaded->supporter_id)->not->toBeNull();
});

test('the database refuses a second copy of one blast for one supporter', function (): void {
    $blast = Blast::factory()->create();
    $supporter = Supporter::factory()->create();

    BlastRecipient::factory()->ofBlast($blast)->forSupporter($supporter)->create();

    // **The module's defining safety property, held by the database.** A claim
    // is an insert, so "has this person already had it?" is answered by the
    // write rather than by a read another worker can race -- which is what makes
    // it survive two workers running the same send at once, the state
    // `retry_after` produces by default on any send longer than 90 seconds.
    $refusal = refusalFrom(fn () => BlastRecipient::factory()
        ->ofBlast($blast)
        ->forSupporter($supporter)
        ->create());

    expect($refusal)->not->toBeNull()
        ->and($refusal?->getMessage())->toContain('blast_recipients_blast_id_supporter_id_unique');
});

test('the same supporter can be written to by two different blasts', function (): void {
    $supporter = Supporter::factory()->create();

    BlastRecipient::factory()->ofBlast(Blast::factory()->create())->forSupporter($supporter)->create();
    BlastRecipient::factory()->ofBlast(Blast::factory()->create())->forSupporter($supporter)->create();

    // The positive beside the refusal above, made through the same call in the
    // same run (L-19). A unique index on `blast_id` alone would satisfy every
    // assertion in the previous test and would silently stop a campaign writing
    // to anybody twice in its life.
    expect(BlastRecipient::query()->where('supporter_id', $supporter->getKey())->count())->toBe(2);
});

test('the database refuses a message that both went and failed to go', function (): void {
    $recipient = BlastRecipient::factory()->create();

    $refusal = refusalFrom(fn () => DB::connection('tenant')
        ->table('blast_recipients')
        ->where('id', $recipient->getKey())
        ->update([
            'sent_at' => now(),
            'failure_reason' => 'The address was rejected.',
        ]));

    expect($refusal)->not->toBeNull()
        ->and($refusal?->getMessage())->toContain('blast_recipients_sent_or_failed');
});

test('a claim that was never resolved is a legal row, and says nobody knows', function (): void {
    // The third state the constraint above deliberately permits. A send killed
    // between claiming a recipient and handing the message over leaves this,
    // and it is the true statement: the message was not sent, and nothing can
    // say whether it would have been. Forbidding it would force the writer to
    // guess one of the two answers.
    $recipient = BlastRecipient::factory()->create();

    expect($recipient->sent_at)->toBeNull()
        ->and($recipient->failure_reason)->toBeNull();

    $this->assertDatabaseHas('blast_recipients', [
        'id' => $recipient->getKey(),
        'sent_at' => null,
        'failure_reason' => null,
    ], 'tenant');
});

test('erasing a supporter forgets who was written to and keeps that they were', function (): void {
    // **D-10 measured by running a deletion and counting rows** (Blueprint §5),
    // rather than by reading the schema -- which is the practice that found the
    // audit trail retaining a deleted operator's address in Phase 0.
    $blast = Blast::factory()->create();
    $staying = Supporter::factory()->create();
    $leaving = Supporter::factory()->create();

    BlastRecipient::factory()->ofBlast($blast)->forSupporter($staying)->sent()->create();
    BlastRecipient::factory()->ofBlast($blast)->forSupporter($leaving)->sent()->create();

    $before = BlastRecipient::query()->where('blast_id', $blast->getKey())->count();

    $leaving->delete();

    $after = BlastRecipient::query()->where('blast_id', $blast->getKey())->count();

    // The count is unchanged, so the campaign's record of how many people it
    // reached is not quietly rewritten by an erasure -- which is what cascading
    // would have done, in the direction that understates what a campaign did.
    expect($before)->toBe(2)
        ->and($after)->toBe(2);

    // And the identity is gone, which is the half that makes D-10 true. Read
    // through the query builder rather than the model, so a relation or an
    // accessor cannot stand in for the stored bytes.
    $orphaned = DB::connection('tenant')->table('blast_recipients')
        ->where('blast_id', $blast->getKey())
        ->whereNull('supporter_id')
        ->count();

    expect($orphaned)->toBe(1)
        ->and(Supporter::query()->whereKey($leaving->getKey())->exists())->toBeFalse();
});

test('two erased supporters leave two rows rather than colliding on the unique index', function (): void {
    // PostgreSQL does not treat two nulls as equal, which is what the index
    // needs here: the erased are different people, and the index exists to stop
    // one person being written to twice rather than to count nulls. Worth an
    // assertion because the opposite behaviour -- one null per blast -- would
    // silently delete a row on the *second* erasure, long after anyone was
    // looking.
    $blast = Blast::factory()->create();

    foreach ([Supporter::factory()->create(), Supporter::factory()->create()] as $supporter) {
        BlastRecipient::factory()->ofBlast($blast)->forSupporter($supporter)->sent()->create();
        $supporter->delete();
    }

    expect(BlastRecipient::query()->where('blast_id', $blast->getKey())->count())->toBe(2);
});

test('deleting a blast takes its recipients with it', function (): void {
    $blast = Blast::factory()->create();
    BlastRecipient::factory()->ofBlast($blast)->create();

    $other = Blast::factory()->create();
    BlastRecipient::factory()->ofBlast($other)->create();

    $blast->delete();

    // **Counted as rows that still exist, not as rows still pointing at the
    // deleted blast, and the difference is the whole guard.** Written the
    // obvious way -- "no rows carry this blast_id" -- it is satisfied identically
    // by a cascade and by a null-on-delete, and breaking the migration to null
    // instead of cascade reddened nothing at all. An orphaned row is the outcome
    // worth forbidding: it names a message that no longer exists, in a table
    // where nothing else refers to it, and it would be counted forever by
    // anything asking how many people this campaign has written to.
    expect(BlastRecipient::query()->count())->toBe(1)
        ->and(DB::connection('tenant')->table('blast_recipients')->whereNull('blast_id')->count())->toBe(0)
        // The surviving row is the control: a cascade that took everything
        // would satisfy both assertions above.
        ->and(BlastRecipient::query()->where('blast_id', $other->getKey())->count())->toBe(1);
});
