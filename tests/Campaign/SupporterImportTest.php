<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Supporters\ColumnMapping;
use App\Supporters\ImportStatus;
use App\Supporters\ImportSupporters;
use App\Supporters\NameColumnMode;
use App\Supporters\SubscriptionStatus;
use App\Supporters\SupporterFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StagedImport;

/*
 * Reading an uploaded list into the campaign's supporters.
 *
 * The job is run here rather than queued: the suite's QUEUE_CONNECTION is
 * `sync`, so what these tests exercise is the reading and the writing, in the
 * campaign the test is already inside. That the campaign *reaches* a job at all
 * is a different claim about a different mechanism, and it is made in
 * tests/Tenancy/CampaignSupporterImportTest.php through a real worker with two
 * campaigns -- which is the only place it can be made, since sync raises none of
 * the events the switching is built on.
 */

test('a file with two name columns records both parts and joins the display name', function (): void {
    $import = StagedImport::of(<<<'CSV'
        First,Last,Email,Postcode
        Ama,Boateng,ama.boateng@example.test,M15 6BH
        CSV, StagedImport::splitMapping());

    (new ImportSupporters($import))->handle();

    $supporter = Supporter::query()->whereEmailMatches('ama.boateng@example.test')->sole();

    // The parts are what the source told us and the display string is the join
    // of them -- recomputable precisely because the parts are kept.
    expect($supporter->given_name)->toBe('Ama')
        ->and($supporter->family_name)->toBe('Boateng')
        ->and($supporter->name)->toBe('Ama Boateng')
        ->and($supporter->postcode)->toBe('M15 6BH')
        ->and($supporter->subscription_status)->toBe(SubscriptionStatus::Subscribed);

    expect($import->fresh())
        ->status->toBe(ImportStatus::Completed)
        ->rows_read->toBe(1)
        ->supporters_added->toBe(1)
        ->supporters_updated->toBe(0)
        ->rows_skipped->toBe(0)
        ->and($import->fresh()->finished_at)->not->toBeNull();
});

test('a file with one name column leaves both parts null rather than inventing a boundary', function (): void {
    // The rule the whole name design exists for: a single-string source has not
    // told us where the split falls, so nothing splits it. `Sukarno` is the case
    // that makes fabrication visible -- there is no boundary to find at all.
    $import = StagedImport::of(<<<'CSV'
        Full name,Email
        Sukarno,sukarno@example.test
        CSV, (new ColumnMapping(
        email: 'Email',
        nameMode: NameColumnMode::Single,
        name: 'Full name',
    ))->toArray());

    (new ImportSupporters($import))->handle();

    $supporter = Supporter::query()->whereEmailMatches('sukarno@example.test')->sole();

    expect($supporter->name)->toBe('Sukarno')
        ->and($supporter->given_name)->toBeNull()
        ->and($supporter->family_name)->toBeNull();
});

test('a file with no name column at all still imports contactable people', function (): void {
    // What a petition widget that asked only for an address produces. Refusing
    // these would refuse people the campaign can perfectly well reach.
    $import = StagedImport::of(<<<'CSV'
        Email
        petition-signer@example.test
        CSV, StagedImport::addressOnlyMapping());

    (new ImportSupporters($import))->handle();

    $supporter = Supporter::query()->whereEmailMatches('petition-signer@example.test')->sole();

    expect($supporter->name)->toBeNull()
        ->and($supporter->given_name)->toBeNull()
        ->and($supporter->family_name)->toBeNull();
});

test('a supporter already on the list is corrected in place, keeping the casing first recorded', function (): void {
    // D-8: the address is the identity, matched on lower(email) and stored
    // exactly as given. A second file spelling it differently is the same
    // person, and the spelling the campaign already has is the one it keeps --
    // which is also what stops an operator's hand correction being undone by the
    // next upload.
    $existing = Supporter::query()->create([
        'name' => 'Ines Duarte',
        'given_name' => 'Ines',
        'family_name' => 'Duarte',
        'email' => 'Ines.Duarte@Example.test',
        'postcode' => '1250-096',
    ]);

    $import = StagedImport::of(<<<'CSV'
        First,Last,Email,Postcode
        Ines,Duarte,ines.duarte@example.test,1000-001
        CSV, StagedImport::splitMapping());

    (new ImportSupporters($import))->handle();

    expect(Supporter::query()->count())->toBe(1);

    $reloaded = $existing->fresh();

    expect($reloaded->email)->toBe('Ines.Duarte@Example.test')
        ->and($reloaded->postcode)->toBe('1000-001')
        ->and($reloaded->getKey())->toBe($existing->getKey());

    expect($import->fresh())
        ->supporters_added->toBe(0)
        ->supporters_updated->toBe(1);
});

test('a blank cell means the file did not say, never forget what you knew', function (): void {
    // Measured before this was written: a plain upsert update list wipes a
    // stored name and postcode to null when the incoming cells are empty. Real
    // exports are full of partly-filled rows, so that shape would let one thin
    // file erase everything a campaign had collected.
    $existing = Supporter::query()->create([
        'name' => 'Ama Boateng',
        'given_name' => 'Ama',
        'family_name' => 'Boateng',
        'email' => 'ama.boateng@example.test',
        'postcode' => 'M15 6BH',
    ]);

    $import = StagedImport::of(<<<'CSV'
        First,Last,Email,Postcode
        ,,ama.boateng@example.test,
        CSV, StagedImport::splitMapping());

    (new ImportSupporters($import))->handle();

    $reloaded = $existing->fresh();

    expect($reloaded->name)->toBe('Ama Boateng')
        ->and($reloaded->given_name)->toBe('Ama')
        ->and($reloaded->family_name)->toBe('Boateng')
        ->and($reloaded->postcode)->toBe('M15 6BH');
});

test('an import cannot put back somebody who asked not to be contacted', function (): void {
    // The reason unsubscribing is a status rather than a deletion. If a later
    // file could reverse it, the record would be worthless and the campaign
    // would mail somebody who told it not to.
    $quiet = Supporter::query()->create([
        'name' => 'Quiet',
        'email' => 'quiet@example.test',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    $import = StagedImport::of(<<<'CSV'
        First,Last,Email,Postcode
        Quiet,Person,quiet@example.test,
        CSV, StagedImport::splitMapping());

    (new ImportSupporters($import))->handle();

    expect($quiet->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed);
});

test('two rows differing only in the case of the address are one person, not a crash', function (): void {
    // Measured as an outright failure before this was handled: PostgreSQL
    // refuses an upsert whose own batch collides with itself --
    // "ON CONFLICT DO UPDATE command cannot affect row a second time"
    // (SQLSTATE 21000) -- and it aborts the whole chunk, not the row. Duplicate
    // addresses inside one export are ordinary, so without the fold the first
    // realistic file an operator uploads fails outright.
    $import = StagedImport::of(<<<'CSV'
        First,Last,Email,Postcode
        Jean,Sacren,dup@example.test,
        Jean,Sacren,DUP@Example.test,SW1A 1AA
        CSV, StagedImport::splitMapping());

    (new ImportSupporters($import))->handle();

    $supporter = Supporter::query()->whereEmailMatches('dup@example.test')->sole();

    // The last occurrence wins, for the same reason a later file wins over an
    // earlier one: it is the more recent thing the campaign was told.
    expect($supporter->email)->toBe('DUP@Example.test')
        ->and($supporter->postcode)->toBe('SW1A 1AA');

    expect($import->fresh())
        ->status->toBe(ImportStatus::Completed)
        ->rows_read->toBe(2)
        ->supporters_added->toBe(1);
});

test('a row that names nobody is skipped and counted rather than refusing the file', function (): void {
    // A summary line at the foot of an export, or a row somebody left half
    // filled. The address is the identity and there is no second channel, so a
    // row without a usable one names nobody -- but throwing away the other rows
    // over it would be far worse, and a silent drop worse still.
    $import = StagedImport::of(<<<'CSV'
        First,Last,Email,Postcode
        Real,Person,real.person@example.test,
        No,Address,,
        Bad,Address,not-an-address,
        CSV, StagedImport::splitMapping());

    (new ImportSupporters($import))->handle();

    expect(Supporter::query()->count())->toBe(1);

    expect($import->fresh())
        ->status->toBe(ImportStatus::Completed)
        ->rows_read->toBe(3)
        ->supporters_added->toBe(1)
        ->rows_skipped->toBe(2);
});

test('a header written with a byte-order mark still names its column', function (): void {
    // A spreadsheet application writes one at the head of a UTF-8 export. It is
    // invisible in every editor and it is part of the first header's name to any
    // string comparison, so unstripped it would make the operator's mapping miss
    // and every row import with no address at all.
    $import = StagedImport::of("\u{FEFF}Email\nbom@example.test\n",
        StagedImport::addressOnlyMapping());

    expect($import->headers)->toBe(['Email']);

    (new ImportSupporters($import))->handle();

    expect(Supporter::query()->whereEmailMatches('bom@example.test')->exists())->toBeTrue();
});

test('a blank line in the file is not a person', function (): void {
    // A blank line between blocks, and a file that ends with one, are both
    // ordinary in a hand-edited export. fgetcsv reports such a line as a single
    // null cell rather than as an empty array, so it arrives looking exactly
    // like a row -- one that would be counted as read and then skipped as
    // nameless, reporting to the operator that their file contained rows it did
    // not. (A file merely ending in a single newline is a different thing and
    // produces no row at all, which is why this uses two.)
    $import = StagedImport::of(
        "First,Last,Email,Postcode\nReal,Person,real.person@example.test,\n\nAlso,Real,also.real@example.test,\n\n",
        StagedImport::splitMapping(),
    );

    (new ImportSupporters($import))->handle();

    expect(Supporter::query()->count())->toBe(2);

    expect($import->fresh())
        ->rows_read->toBe(2)
        ->supporters_added->toBe(2)
        ->rows_skipped->toBe(0);
});

test('a failed run records why, in the campaign the operator can see', function (): void {
    // Central failed_jobs carries no campaign column and no campaign surface
    // reads it, so a failure that only landed there would leave the operator who
    // uploaded the file with nothing at all. The job's failed() hook runs in
    // campaign context -- measured -- which is what makes this possible.
    $import = StagedImport::of(<<<'CSV'
        Email
        someone@example.test
        CSV, StagedImport::addressOnlyMapping());

    Storage::disk(SupporterFile::DISK)->delete($import->stored_path);

    $job = new ImportSupporters($import);

    try {
        $job->handle();
    } catch (Throwable $exception) {
        $job->failed($exception);
    }

    expect($import->fresh())
        ->status->toBe(ImportStatus::Failed)
        ->and($import->fresh()->failure_reason)->toContain('could not be read')
        ->and($import->fresh()->finished_at)->not->toBeNull();
});

test('an import without a mapping refuses to read the file', function (): void {
    // The mapping is the operator's statement about their own file, and there is
    // no default for it. Reaching the job without one can only happen if
    // something queued around the surface that collects it, so it says what is
    // wrong rather than importing a file's worth of rows keyed on nothing.
    $import = StagedImport::of(<<<'CSV'
        Email
        someone@example.test
        CSV);

    expect(fn () => (new ImportSupporters($import))->handle())
        ->toThrow(RuntimeException::class, 'no column mapping');

    expect(Supporter::query()->count())->toBe(0);
});

test('the status column defaults to an import nobody has mapped yet', function (): void {
    // Written straight to the table, bypassing Eloquent, so the value under test
    // can only have come from the database. This is what keeps the migration
    // frozen: it hardcodes 'awaiting_mapping' rather than reading
    // ImportStatus::default(), because it re-runs for every campaign at whatever
    // date that campaign is provisioned, and a default read out of application
    // code would give campaigns created after an edit a different schema from
    // the ones already provisioned.
    DB::connection('tenant')->table('supporter_imports')->insert([
        'original_filename' => 'raw.csv',
        'stored_path' => 'imports/raw.csv',
        'headers' => json_encode(['Email']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stored = DB::connection('tenant')->table('supporter_imports')
        ->where('original_filename', 'raw.csv')
        ->value('status');

    // Pinned to each other, so the literal and the enum cannot drift apart...
    expect($stored)->toBe(ImportStatus::default()->value);

    // ...and pinned to the intended choice, so the pair cannot move together and
    // stay green. An import that started itself would be one that guessed a
    // mapping, which is the fabrication this module exists to prevent.
    expect(ImportStatus::default())->toBe(ImportStatus::AwaitingMapping);
});
