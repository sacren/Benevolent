<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Supporters\StartSupporterImportRequest;
use App\Http\Requests\Supporters\StoreSupporterImportRequest;
use App\Models\Supporter;
use App\Models\SupporterImport;
use App\Supporters\ColumnMapping;
use App\Supporters\ImportStatus;
use App\Supporters\ImportSupporters;
use App\Supporters\NameColumnMode;
use App\Supporters\SupporterFile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Bringing an existing list into the campaign.
 *
 * **Authority comes from SupporterPolicy's `import` ability, never from a
 * permission string.** Every action here asks the same one, including the read:
 * an import record carries the counts of what was done to the campaign's list
 * and the name of the file it came from, so seeing one is part of importing
 * rather than a separate lesser thing. The ability is checked against
 * Supporter::class rather than against the import, because the policy governs
 * the people the file describes -- SupporterImport has no policy of its own for
 * exactly that reason.
 *
 * **Two steps, because a mapping is a statement about a file.** The upload
 * arrives with no mapping at all, its headers are read, and only then is the
 * operator asked what those headers mean, with the file's own column names in
 * front of them. Asking first would mean asking somebody to type header names
 * from memory, and a typo there does not fail -- it reads a column that is not
 * there for every row and reports a clean import of nothing.
 */
class SupporterImportController extends Controller
{
    /**
     * Applied here rather than to the base class for the reason
     * SupporterController records: Laravel 11 emptied the base Controller, and
     * raising the trait would hand every future controller an authorization
     * surface on behalf of the ones that asked.
     */
    use AuthorizesRequests;

    /**
     * Show the form for uploading a list.
     */
    public function create(): Response
    {
        $this->authorize('import', Supporter::class);

        return Inertia::render('supporters/imports/Create');
    }

    /**
     * Take the file, read its headers, and record an import waiting to be
     * mapped.
     */
    public function store(StoreSupporterImportRequest $request): RedirectResponse
    {
        $this->authorize('import', Supporter::class);

        $file = $request->file('file');

        // Stored on the campaign's own disk, whose root the filesystem
        // bootstrapper has already pointed inside this campaign's storage tree
        // -- so one campaign's uploaded list is not reachable from another even
        // by path, and deleting the campaign takes the file with it.
        $path = $file->store('imports', SupporterFile::DISK);

        if ($path === false) {
            // The disk refused the write. That is a server fault rather than
            // anything the operator can correct, so it is raised rather than
            // dressed up as a validation message -- and raised rather than
            // ignored, because carrying on would read the headers of a file
            // that is not there and record an import of nothing.
            throw new RuntimeException('The uploaded list could not be stored.');
        }

        try {
            $headers = SupporterFile::headers($path);
        } catch (RuntimeException) {
            // Something with a .csv name that is not a readable file. Refused
            // where the operator can read the reason rather than 500ing, and the
            // upload is removed rather than left as an orphan nothing will ever
            // point at.
            Storage::disk(SupporterFile::DISK)->delete($path);

            throw ValidationException::withMessages([
                'file' => __('That file could not be read as a list.'),
            ]);
        }

        if ($headers === []) {
            Storage::disk(SupporterFile::DISK)->delete($path);

            throw ValidationException::withMessages([
                'file' => __('That file has no column headings, so there is nothing to map.'),
            ]);
        }

        $import = SupporterImport::query()->create([
            'operator_id' => $request->user()?->getKey(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'headers' => $headers,
        ]);

        return to_route('supporters.imports.show', $import);
    }

    /**
     * Show what this import is waiting for, doing, or has done.
     *
     * One page for all three because they are one thing to an operator: the
     * file they uploaded, and where it has got to. Which of the three it shows
     * is the record's own status.
     */
    public function show(SupporterImport $import): Response
    {
        $this->authorize('import', Supporter::class);

        return Inertia::render('supporters/imports/Show', [
            'import' => $import,
            'operator' => $import->operator?->name,
            'finished' => $import->status->isFinished(),
        ]);
    }

    /**
     * Accept the operator's mapping and queue the reading.
     */
    public function start(StartSupporterImportRequest $request, SupporterImport $import): RedirectResponse
    {
        $this->authorize('import', Supporter::class);

        if ($import->status !== ImportStatus::AwaitingMapping) {
            // A second submission of a form the operator left open, or a
            // refresh. Re-reading the file would double neither the supporters
            // nor the harm -- the writes are upserts -- but it would overwrite
            // the counts of a run that already happened, so the honest answer is
            // that this import has already been given its instructions.
            throw ValidationException::withMessages([
                'name_mode' => __('This import has already been started.'),
            ]);
        }

        $validated = $request->validated();

        $mapping = new ColumnMapping(
            email: $validated['email'],
            nameMode: NameColumnMode::from($validated['name_mode']),
            name: $validated['name'] ?? null,
            givenName: $validated['given_name'] ?? null,
            familyName: $validated['family_name'] ?? null,
            postcode: $validated['postcode'] ?? null,
        );

        $import->forceFill([
            'mapping' => $mapping->toArray(),
            'status' => ImportStatus::Pending,
        ])->save();

        ImportSupporters::dispatch($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Import queued.')]);

        return to_route('supporters.imports.show', $import);
    }
}
