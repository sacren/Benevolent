<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\BlastRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The matching claim -- that the central database carries no withdrawals --
// lives in tests/Feature/CentralSchemaTest.php, which pins the central tables
// exhaustively, for the reason L-18 records: this suite rebuilds the central
// schema only when it is missing, so a migration misfiled into the central set
// is never applied during this run and the absence would hold whether or not
// it is true.
//
// Every row here is written past any model, straight to the table the schema
// defines, because these tests are about what the schema accepts rather than
// what the one writer -- the unsubscribe request -- chooses to write.

test('a withdrawal holds when it happened and which copy it followed, and nothing about the person', function (): void {
    // **D-47 and D-49 asked as a column list rather than as a promise**, the
    // exhaustive shape blast_recipients uses for D-10. No supporter key, so an
    // unattributed row names nobody from birth; no status, so nothing can read
    // this table as whether somebody is subscribed; no updated_at, because a
    // row is never revised.
    expect(Schema::getColumnListing('unsubscribes'))->toBe([
        'id',
        'blast_recipient_id',
        'created_at',
    ]);
});

test('a withdrawal may name no message, and one message may be followed by more than one', function (): void {
    // Both halves of D-47's deciding questions, stated as rows the database
    // accepts. A link mailed before messages named themselves carries only
    // the supporter's token, so its withdrawal is recorded with no message.
    // And the same link, followed after an operator has put somebody back on
    // the list, is a second withdrawal -- measured as a second real change of
    // status -- so one copy of one message can precede two rows.
    $recipient = BlastRecipient::factory()->sent()->create();

    DB::connection('tenant')->table('unsubscribes')->insert([
        ['blast_recipient_id' => $recipient->getKey(), 'created_at' => now()->subDay()],
        ['blast_recipient_id' => $recipient->getKey(), 'created_at' => now()],
        ['blast_recipient_id' => null, 'created_at' => now()],
    ]);

    expect(DB::connection('tenant')->table('unsubscribes')->where('blast_recipient_id', $recipient->getKey())->count())->toBe(2)
        ->and(DB::connection('tenant')->table('unsubscribes')->whereNull('blast_recipient_id')->count())->toBe(1);
});

test('a withdrawal goes with the message it names rather than becoming unattributed', function (): void {
    $blast = Blast::factory()->create();
    $recipient = BlastRecipient::factory()->ofBlast($blast)->sent()->create();

    $other = BlastRecipient::factory()->sent()->create();

    DB::connection('tenant')->table('unsubscribes')->insert([
        ['blast_recipient_id' => $recipient->getKey(), 'created_at' => now()],
        ['blast_recipient_id' => $other->getKey(), 'created_at' => now()],
    ]);

    $blast->delete();

    // **Counted as unattributed rows, because that is the defect.** A null on
    // delete would leave the row standing with no message, which reads as a
    // withdrawal through a link that could not name its message -- a false
    // statement about how somebody left, and exactly the unattributed count
    // this table exists to keep honest.
    expect(DB::connection('tenant')->table('unsubscribes')->whereNull('blast_recipient_id')->count())->toBe(0)
        // The surviving row is the control: a delete that took everything
        // would satisfy the assertion above.
        ->and(DB::connection('tenant')->table('unsubscribes')->pluck('blast_recipient_id')->all())
        ->toBe([$other->getKey()]);

    // The configuration invariant behind the behaviour. The rows above are
    // one deletion's outcome; this names the rule producing it.
    $foreignKey = collect(Schema::getForeignKeys('unsubscribes'))->sole();

    expect($foreignKey['columns'])->toBe(['blast_recipient_id'])
        ->and($foreignKey['foreign_table'])->toBe('blast_recipients')
        ->and($foreignKey['on_delete'])->toBe('cascade');
});
