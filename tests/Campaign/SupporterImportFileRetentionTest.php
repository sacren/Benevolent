<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\SupporterImport;
use App\Supporters\ImportStatus;
use App\Supporters\ImportSupporters;
use App\Supporters\SupporterFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StagedImport;

/*
 * How long a campaign keeps the list it was sent.
 *
 * **This is the erasure half of Step 5, and it is worth saying why erasure is a
 * retention command rather than something on the delete path.** Deleting a
 * supporter removes one row. The CSV they arrived in still names them; it also
 * names every row that import *skipped* for want of a usable address, and those
 * people were never in the table at all, so no row-level deletion can reach
 * them at any level of effort. Bounding the file's life is the only mechanism
 * that reaches everyone the file names. That is D-10's answer, and the first
 * test below is the one that states the part a schema read would never suggest.
 *
 * What is deliberately *not* pruned is the import record. Measured before this
 * was designed: a record holds the operator's filename, the counts, the mapping
 * and the file's header row -- and the header row is column names rather than
 * people. So the campaign's account of what happened to its own list carries no
 * supporter's details and has no reason to expire, while the file does.
 */

test('a pruned upload stops naming the people the campaign already deleted', function (): void {
    // The whole argument in one run. Two people arrive in a file; one is
    // imported and then deleted the way the list page deletes them, and the
    // other has no usable address so is skipped and never becomes a row at all.
    // Before the prune, the file still names both. Afterwards it names neither,
    // and the campaign's account of the import is untouched.
    $import = StagedImport::of(
        "Email,First,Last,Postcode\n"
        ."jean@example.test,Jean,Sacren,80202\n"
        ."not-an-address,Alex,Roe,80203\n",
        StagedImport::splitMapping(),
    );

    (new ImportSupporters($import))->handle();

    $import->refresh();

    expect($import->rows_read)->toBe(2)
        ->and($import->supporters_added)->toBe(1)
        ->and($import->rows_skipped)->toBe(1);

    Supporter::query()->whereEmailMatches('jean@example.test')->delete();

    // The state this step exists to end: the row is gone and the file is not.
    $path = (string) $import->stored_path;

    expect(Supporter::query()->whereEmailMatches('jean@example.test')->exists())->toBeFalse()
        ->and(Storage::disk(SupporterFile::DISK)->get($path))->toContain('jean@example.test')
        // And the person no deletion could ever have reached, because they were
        // skipped and never became a row.
        ->and(Storage::disk(SupporterFile::DISK)->get($path))->toContain('Alex,Roe');

    $import->forceFill(['created_at' => now()->subDays(8)])->save();

    Artisan::call('supporters:prune-import-files');

    $import->refresh();

    expect(Storage::disk(SupporterFile::DISK)->exists($path))->toBeFalse()
        // The record's own answer to "do we still hold that file", which is what
        // an operator needs when somebody asks what is kept about them. A path
        // left pointing at nothing could not tell a deliberate removal from a
        // lost file.
        ->and($import->stored_path)->toBeNull()
        // The account of what happened to the list survives in full.
        ->and($import->rows_read)->toBe(2)
        ->and($import->supporters_added)->toBe(1)
        ->and($import->rows_skipped)->toBe(1)
        ->and($import->original_filename)->toBe('supporters.csv')
        ->and($import->headers)->toBe(['Email', 'First', 'Last', 'Postcode']);
});

test('an upload inside the window is left alone', function (): void {
    // The other direction, and without it the test above is satisfied by a
    // command that deletes every uploaded file it can find.
    $import = StagedImport::of(
        "Email\nrecent@example.test\n",
        StagedImport::addressOnlyMapping(),
    );

    $path = (string) $import->stored_path;

    $import->forceFill(['created_at' => now()->subDays(6)])->save();

    Artisan::call('supporters:prune-import-files');

    expect(Storage::disk(SupporterFile::DISK)->exists($path))->toBeTrue()
        ->and($import->refresh()->stored_path)->toBe($path);
});

test('the window is seven days, and it is the command that says so', function (): void {
    // The configuration invariant behind the behaviour, paired with it because
    // either alone is weak: the two tests above would both pass with a window of
    // any length between six and eight days, so neither pins the number that
    // routes/console.php relies on by passing no option at all.
    $justInside = StagedImport::of("Email\ninside@example.test\n", StagedImport::addressOnlyMapping());
    $justOutside = StagedImport::of("Email\noutside@example.test\n", StagedImport::addressOnlyMapping());

    $insidePath = (string) $justInside->stored_path;
    $outsidePath = (string) $justOutside->stored_path;

    $justInside->forceFill(['created_at' => now()->subDays(7)->addMinutes(5)])->save();
    $justOutside->forceFill(['created_at' => now()->subDays(7)->subMinutes(5)])->save();

    Artisan::call('supporters:prune-import-files');

    expect(Storage::disk(SupporterFile::DISK)->exists($insidePath))->toBeTrue()
        ->and(Storage::disk(SupporterFile::DISK)->exists($outsidePath))->toBeFalse();
});

test('an abandoned upload is pruned although nothing ever read it', function (): void {
    // No status exception, and this is the case that makes the rule earn it. An
    // import nobody ever mapped holds a whole list on disk that was never
    // consumed -- the worst of the four states, not an edge of them -- and a
    // command that skipped unfinished imports would keep it forever.
    $import = StagedImport::of("Email,First,Last,Postcode\nabandoned@example.test,Jean,Sacren,80202\n");

    expect($import->status)->toBe(ImportStatus::AwaitingMapping);

    $path = (string) $import->stored_path;

    $import->forceFill(['created_at' => now()->subDays(8)])->save();

    Artisan::call('supporters:prune-import-files');

    expect(Storage::disk(SupporterFile::DISK)->exists($path))->toBeFalse()
        ->and($import->refresh()->stored_path)->toBeNull()
        // Still an import that was never mapped. The prune says nothing about
        // what happened to the file's contents, only that they are not held.
        ->and($import->status)->toBe(ImportStatus::AwaitingMapping);
});

test('running the prune twice removes nothing the second time', function (): void {
    // Idempotence is the property a daily schedule actually needs, and it is
    // what nulling the column buys: the second run selects nothing rather than
    // asking the filesystem about every import the campaign has ever had.
    StagedImport::of("Email\nfirst@example.test\n", StagedImport::addressOnlyMapping())
        ->forceFill(['created_at' => now()->subDays(8)])->save();

    Artisan::call('supporters:prune-import-files');
    $first = Artisan::output();

    Artisan::call('supporters:prune-import-files');
    $second = Artisan::output();

    expect($first)->toContain('1 uploaded list removed')
        ->and($second)->toContain('0 uploaded lists removed');
});

test('an import whose file has been pruned refuses to run, and says why', function (): void {
    // The consequence of the column being nullable, met where it would actually
    // be met. Reachable only if nothing worked the queue for longer than the
    // window, and the message has to name that rather than the file, because the
    // remedies differ: nothing is wrong with the upload, the campaign simply
    // does not have it any more.
    $import = StagedImport::of(
        "Email\nlate@example.test\n",
        StagedImport::addressOnlyMapping(),
    );

    $import->forceFill(['created_at' => now()->subDays(8)])->save();

    Artisan::call('supporters:prune-import-files');

    expect(fn () => (new ImportSupporters($import->refresh()))->handle())
        ->toThrow(RuntimeException::class, 'no longer held');

    expect(Supporter::query()->count())->toBe(0);
});

test('nothing prunes the import record itself', function (): void {
    // The deliberate absence, stated so that adding Prunable to SupporterImport
    // -- the obvious next thing somebody reaches for -- reddens rather than
    // passing as an improvement. The record holds the operator's filename, the
    // counts, the mapping and the file's header row, and the header row is
    // column names rather than people, so there is nothing in it to expire.
    $import = StagedImport::of(
        "Email,First,Last,Postcode\nkept@example.test,Jean,Sacren,80202\n",
        StagedImport::splitMapping(),
    );

    $import->forceFill(['created_at' => now()->subYears(3)])->save();

    Artisan::call('supporters:prune-import-files');
    Artisan::call('model:prune');

    expect(SupporterImport::query()->whereKey($import->getKey())->exists())->toBeTrue()
        ->and($import->refresh()->headers)->toBe(['Email', 'First', 'Last', 'Postcode']);
});
