<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupporterImport;
use App\Supporters\SupporterFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Stops a campaign holding the lists it was sent, once it has read them.
 *
 * **This is the erasure half of Step 5, and it is a command rather than
 * anything on a page for a reason worth stating.** Deleting a supporter removes
 * one row. The file they arrived in still names them — and still names everyone
 * that import *skipped* for want of a usable address, who were never in the
 * table and so can never be reached by deleting a row at all. No row-level
 * erasure can touch those people at any level of effort. Bounding how long the
 * file exists is the only mechanism that reaches every person it names, which
 * is what settled D-10.
 *
 * **The record is kept; only the file goes.** An import row carries the
 * operator's filename, the counts, the mapping and the file's header row.
 * Measured rather than assumed before this was built: the header row is column
 * names, not people, so the campaign's account of what happened to its list
 * holds no supporter's details and has no reason to expire. `stored_path` is
 * nulled as the file is deleted, so the record's own answer to "do we still
 * hold that file" is truthful rather than merely quiet.
 *
 * **Aged from the upload rather than from the outcome, with no exception for
 * status.** The file's purpose expires by age: once an import has run, nothing
 * re-reads it, and what is left is a window in which somebody might want to see
 * what the file actually said. An import still waiting to be mapped after the
 * window is abandoned, and it is the worst case of all — a whole list on disk
 * that nobody ever consumed. An import still queued after the window means
 * nothing has worked the queue for a week, and the failure it then records
 * ("the uploaded file could not be read") describes that situation accurately
 * rather than confusingly. One rule, no unbounded case.
 *
 * **Belongs under `tenants:run`.** Both the records and the disk are the
 * campaign's: `supporter_imports` lives in the tenant migration set, and the
 * `local` disk is re-rooted into the campaign's own storage tree. A central
 * invocation reaches neither, which is the shape Phase 0 Step 11 found in the
 * framework's own `auth:clear-resets` — so this refuses centrally with a
 * sentence rather than dying on a missing relation.
 *
 * **Filed in app/Console/Commands/ rather than with its module, and that is the
 * exact inverse of D-6.** A job is discovered by nothing, so where it sits is
 * filing and it goes with its module. A command *is* discovered by path —
 * measured: `campaign:create` is registered by nothing else in this
 * application — so where it sits is wiring, and wiring goes where the framework
 * looks.
 */
#[Signature('supporters:prune-import-files {--days=7 : How many days an uploaded list is kept after it arrives}')]
#[Description('Delete uploaded supporter lists this campaign has finished with, keeping the record of each import.')]
final class PruneImportFiles extends Command
{
    public function handle(): int
    {
        if (tenant() === null) {
            // Said plainly rather than left to fail on a missing relation. There
            // is no central supporter_imports table and no central campaign
            // storage tree, so running this outside a campaign is not a smaller
            // version of the job -- it is a different question with no answer.
            $this->components->error(
                'This command works on one campaign\'s uploads. Run it through `tenants:run supporters:prune-import-files`.'
            );

            return self::FAILURE;
        }

        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $removed = 0;

        SupporterImport::query()
            ->whereNotNull('stored_path')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function (Collection $imports) use (&$removed): void {
                /** @var Collection<int, SupporterImport> $imports */
                foreach ($imports as $import) {
                    Storage::disk(SupporterFile::DISK)->delete((string) $import->stored_path);

                    // Nulled whether or not the delete found anything, because
                    // the claim being recorded is "this campaign no longer holds
                    // that file" -- which is true of a file already gone as much
                    // as of one just removed. Making the record conditional on
                    // the filesystem would leave a path pointing at nothing,
                    // which is the state this column was made nullable to stop.
                    $import->forceFill(['stored_path' => null])->save();

                    $removed++;
                }
            });

        $this->components->info(
            $removed === 1
                ? '1 uploaded list removed; its import record was kept.'
                : "{$removed} uploaded lists removed; their import records were kept."
        );

        return self::SUCCESS;
    }
}
