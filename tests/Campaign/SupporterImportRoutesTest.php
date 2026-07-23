<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\SupporterImport;
use App\Models\User;
use App\Supporters\ImportStatus;
use App\Supporters\ImportSupporters;
use App\Supporters\NameColumnMode;
use App\Supporters\SupporterFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StagedImport;

/*
 * Importing a list the way an operator does it: over HTTP, on the campaign's
 * own hostname, signed in.
 *
 * tests/Campaign/SupporterImportTest.php asks what the reading does to the
 * list, and tests/Tenancy/CampaignSupporterImportTest.php asks which campaign
 * it does it in. This file asks the questions in between -- whether the pages
 * an operator loads ask the policy at all, whether the mapping they submit is
 * checked against their own file, and whether the job is actually queued rather
 * than run in the request.
 */

test('an operator can reach the upload form', function (): void {
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters/import'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('supporters/imports/Create'));
});

test('uploading a list records it, reads its headers, and asks for nothing else yet', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/import'), [
            'file' => UploadedFile::fake()->createWithContent(
                'members.csv',
                "First,Last,Email,Postcode\nAma,Boateng,ama.boateng@example.test,M15 6BH\n",
            ),
        ])
        ->assertRedirect();

    $import = SupporterImport::query()->sole();

    // The headers are read and kept so the operator can be shown their own
    // file's columns. Nothing has decided what any of them *mean*.
    expect($import->headers)->toBe(['First', 'Last', 'Email', 'Postcode'])
        ->and($import->status)->toBe(ImportStatus::AwaitingMapping)
        ->and($import->mapping)->toBeNull()
        ->and($import->original_filename)->toBe('members.csv');

    // Nothing is queued by the upload alone, because there is nothing yet to
    // tell a job how to read the file.
    Queue::assertNothingPushed();

    // Stored on the campaign's own disk, where the reading will look for it.
    expect(Storage::disk(SupporterFile::DISK)->exists($import->stored_path))->toBeTrue();
});

test('the upload records who ran it', function (): void {
    Queue::fake();

    $operator = User::factory()->create(['name' => 'Demo Operator']);

    $this->actingAs($operator)
        ->post($this->campaignUrl('/supporters/import'), [
            'file' => UploadedFile::fake()->createWithContent('members.csv', "Email\nsomeone@example.test\n"),
        ]);

    expect(SupporterImport::query()->sole()->operator_id)->toBe($operator->getKey());
});

test('a file that is not a list is refused with a reason, and nothing is left on disk', function (): void {
    Queue::fake();

    $before = Storage::disk(SupporterFile::DISK)->allFiles('imports');

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/import'), [
            'file' => UploadedFile::fake()->createWithContent('empty.csv', ''),
        ])
        ->assertSessionHasErrors('file');

    expect(SupporterImport::query()->count())->toBe(0);

    // The orphan matters: a stored file nothing points at is personal data
    // nobody will ever look at again and nothing will ever delete. Counted
    // against what was there before rather than asserted empty, because the
    // campaign's disk is not inside the test's transaction -- files written by
    // earlier tests in this file are still there, and an absolute assertion
    // here would be measuring them instead.
    expect(Storage::disk(SupporterFile::DISK)->allFiles('imports'))->toBe($before);
});

test('the mapping is checked against the operator\'s own file', function (): void {
    Queue::fake();

    $import = StagedImport::of("First,Last,Email\nAma,Boateng,ama.boateng@example.test\n");

    // A column the file does not contain is the worst failure this module can
    // have if it gets through: it imports as blank for every row and reports a
    // clean run, which looks exactly like success.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email Address',
            'name_mode' => NameColumnMode::Split->value,
            'given_name' => 'First',
            'family_name' => 'Last',
        ])
        ->assertSessionHasErrors('email');

    expect($import->fresh()->status)->toBe(ImportStatus::AwaitingMapping);

    Queue::assertNothingPushed();
});

test('a split mapping must name both parts, because nothing will infer the other', function (): void {
    Queue::fake();

    $import = StagedImport::of("First,Last,Email\nAma,Boateng,ama.boateng@example.test\n");

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email',
            'name_mode' => NameColumnMode::Split->value,
            'given_name' => 'First',
        ])
        ->assertSessionHasErrors('family_name');

    Queue::assertNothingPushed();
});

test('a file with no name column needs no name column named', function (): void {
    Queue::fake();

    $import = StagedImport::of("Email\npetition-signer@example.test\n");

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email',
            'name_mode' => NameColumnMode::None->value,
        ])
        ->assertSessionHasNoErrors();

    expect($import->fresh()->status)->toBe(ImportStatus::Pending);
});

test('accepting the mapping queues the reading rather than doing it in the request', function (): void {
    // **Deliberately not Queue::fake(), and that is the whole reason this test
    // is evidence.** Measured: under the fake, dispatchSync() does not run the
    // job either -- no supporters appear and the record's counts stay at zero --
    // so Queue::assertPushed() passes identically whether the controller queues
    // the work or performs it inline. The guard could not fail. That is L-26's
    // blind spot arriving through a test helper rather than through a worker:
    // the technique driving the test, not the test or its suite.
    //
    // The real database queue tells them apart, because queueing leaves a row
    // and running does not.
    config(['queue.default' => 'database']);

    $central = (string) config('tenancy.database.central_connection');
    DB::connection($central)->table('jobs')->delete();

    $import = StagedImport::of("First,Last,Email,Postcode\nAma,Boateng,ama.boateng@example.test,M15 6BH\n");

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email',
            'name_mode' => NameColumnMode::Split->value,
            'given_name' => 'First',
            'family_name' => 'Last',
            'postcode' => 'Postcode',
        ])
        ->assertRedirect(route('supporters.imports.show', $import));

    // Queued, not run: a file of any size read inside the request would hold
    // the operator's browser open for as long as it took and time out on a real
    // one.
    $queued = DB::connection($central)->table('jobs')->get();

    expect($queued)->toHaveCount(1)
        ->and(json_decode((string) $queued->first()->payload, true)['displayName'])
        ->toBe(ImportSupporters::class);

    // Nothing has read the file yet, so the list is untouched. This is the half
    // that goes red on a synchronous dispatch.
    expect(Supporter::query()->count())->toBe(0);

    // The queue table is central, so it is outside this test's transaction and
    // would otherwise be left for the next test to find.
    DB::connection($central)->table('jobs')->delete();

    $reloaded = $import->fresh();

    expect($reloaded->status)->toBe(ImportStatus::Pending)
        ->and($reloaded->columnMapping()?->email)->toBe('Email')
        ->and($reloaded->columnMapping()?->nameMode)->toBe(NameColumnMode::Split)
        ->and($reloaded->columnMapping()?->givenName)->toBe('First');
});

test('an import already given its instructions will not take them twice', function (): void {
    Queue::fake();

    $import = StagedImport::of("Email\nsomeone@example.test\n", StagedImport::addressOnlyMapping());

    // A refresh, or a form left open in another tab. Re-reading would not
    // duplicate anybody -- the writes are upserts -- but it would overwrite the
    // counts of a run that already happened.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email',
            'name_mode' => NameColumnMode::None->value,
        ])
        ->assertSessionHasErrors('name_mode');

    Queue::assertNothingPushed();
});

test('the import page reports what the run did', function (): void {
    $import = StagedImport::of(
        "First,Last,Email,Postcode\nAma,Boateng,ama.boateng@example.test,M15 6BH\n",
        StagedImport::splitMapping(),
    );

    (new ImportSupporters($import))->handle();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters/imports/'.$import->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('supporters/imports/Show')
            ->where('import.status', ImportStatus::Completed->value)
            ->where('import.supporters_added', 1)
            ->where('import.rows_read', 1)
            ->where('import.original_filename', 'supporters.csv')
            // What stops the page polling forever, answered by the record
            // rather than by the page listing the terminal states itself.
            ->where('finished', true)
        );
});

test('a queued import is reported as unfinished, so the page knows to keep asking', function (): void {
    Queue::fake();

    $import = StagedImport::of("Email\nsomeone@example.test\n", StagedImport::addressOnlyMapping());

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters/imports/'.$import->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('import.status', ImportStatus::Pending->value)
            ->where('finished', false)
        );
});

test('a failed import shows the operator why, on their own campaign\'s page', function (): void {
    $import = StagedImport::of("Email\nsomeone@example.test\n", StagedImport::addressOnlyMapping());

    Storage::disk(SupporterFile::DISK)->delete($import->stored_path);

    $job = new ImportSupporters($import);

    try {
        $job->handle();
    } catch (Throwable $exception) {
        $job->failed($exception);
    }

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters/imports/'.$import->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('import.status', ImportStatus::Failed->value)
            ->where('finished', true)
            ->where('import.failure_reason', 'The uploaded file could not be read.')
        );
});

test('every import route refuses an operator who has lost the grant', function (): void {
    // The deny half, and it cannot fail on its own: a route that 403'd at
    // everybody, or one that did not exist, would satisfy this exactly as a
    // working guard does. What makes it evidence is every other test in this
    // file, where the identical requests succeed.
    //
    // Both roles hold EditSupporters today, so the refusal has to be built
    // rather than found: the grant is withdrawn for the length of this test.
    // That is deliberately the *permission* being withdrawn rather than the
    // policy being stubbed, because it is the shape of the real change -- a
    // role losing a grant -- and it proves each action consults the policy
    // rather than waving every signed-in operator through. The idiom is
    // SupporterListTest's, applied to the four actions this controller has.
    //
    // All four are listed rather than one taken as representative, because
    // authorize() is a separate line in each and dropping any one of them is a
    // separate defect. Without this, removing it from show() reddened nothing
    // at all -- measured.
    $operator = User::factory()->create();

    Gate::define(Permission::EditSupporters->value, fn (): bool => false);

    $import = StagedImport::of("Email\nsomeone@example.test\n");

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters/import'))
        ->assertForbidden();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/supporters/import'), [
            'file' => UploadedFile::fake()->createWithContent('members.csv', "Email\nsomeone@example.test\n"),
        ])
        ->assertForbidden();

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters/imports/'.$import->getKey()))
        ->assertForbidden();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email',
            'name_mode' => NameColumnMode::None->value,
        ])
        ->assertForbidden();

    // Nothing got through, which is the claim rather than the status codes.
    expect(SupporterImport::query()->count())->toBe(1)
        ->and($import->fresh()->status)->toBe(ImportStatus::AwaitingMapping);
});
