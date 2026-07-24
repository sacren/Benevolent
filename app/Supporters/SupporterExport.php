<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\Supporter;
use Illuminate\Support\Collection;

/**
 * Writes the campaign's whole list out as a CSV.
 *
 * **Separate from SupporterFile rather than folded into it, and the two are
 * less alike than the shared word "CSV" suggests.** That class reads a *named
 * file off a disk* and normalizes what it finds there — a byte-order mark to
 * strip, blank header names to drop, short rows to pad — because a file an
 * operator uploaded is somebody else's output. This one writes to an open
 * stream it is handed, touches no filesystem at all, and has nothing to
 * normalize because it is producing the bytes. What they genuinely share is
 * PHP's own fgetcsv/fputcsv, which is not our code. Merging them would make one
 * class out of two that agree on a noun.
 *
 * **Nothing is stored, and that is the point rather than an optimization.** The
 * rows go straight into the response as they are read. A queued export writing
 * a file would create a *fifth* place a campaign's supporters live — after the
 * table, the import records, the uploaded files and central failed_jobs — and
 * every one of those needs its own retention, its own erasure story and its own
 * authorization on the way back out. Step 5 exists to reduce that count, not to
 * add to it.
 *
 * **Trigger to revisit:** a list too large to send inside one request, which is
 * the same list size that makes the index page stop rendering and start paging
 * (Step 6). The two questions move together and should be answered together.
 */
final class SupporterExport
{
    /**
     * How many supporters are read from the database at a time.
     *
     * Bounds the memory a list of any size costs, exactly as the importer's own
     * chunk does. The rows are written to the stream as each chunk arrives, so
     * nothing accumulates between them.
     */
    private const int CHUNK = 500;

    /**
     * The file's header row.
     *
     * Written for a person reading the file rather than for this application
     * reading it back: an export of ours re-imported here goes through the
     * mapping step, where the operator says what each column means, so nothing
     * downstream depends on these exact words.
     *
     * **`id` is deliberately not here.** It is the database's handle on a row
     * rather than anything about the person, and it restarts at 1 in every
     * campaign — the same property that made a campaign-local id the wrong
     * thing to build a lock name from at Step 4. Exported, it would look like a
     * supporter number and mean nothing outside the database it came from.
     *
     * @var list<string>
     */
    private const array HEADER = [
        'Name',
        'Given name',
        'Family name',
        'Email',
        'Postcode',
        'Subscription status',
        'Added on',
    ];

    /**
     * Write every supporter in the current campaign to an open stream.
     *
     * Takes a stream rather than opening one, so the thing that decides where
     * the bytes go is the caller — the controller sends them to php://output,
     * and a test sends them to memory and reads them back. A class that opened
     * php://output itself could only be tested through a response.
     *
     * Ordered oldest first, which is the reverse of the list page and is right
     * for both. The page answers "what has changed lately", so the newest rows
     * belong at the top; a file is read from the beginning, and arrival order is
     * the only history this module keeps. It is also the order chunkById scans
     * in, so the export costs one stable pass with no risk of a row being seen
     * twice or missed as the list changes underneath it.
     *
     * @param  resource  $stream
     */
    public static function writeTo($stream): void
    {
        fputcsv($stream, self::HEADER);

        Supporter::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $supporters) use ($stream): void {
                /** @var Collection<int, Supporter> $supporters */
                foreach ($supporters as $supporter) {
                    fputcsv($stream, self::row($supporter));
                }
            });
    }

    /**
     * What the browser should call the downloaded file.
     *
     * Carries the campaign and the day, because a downloads folder is where two
     * campaigns' exports meet and "supporters.csv" twice over tells nobody
     * which list is which. The campaign's slug is used rather than its display
     * name: it is already the lowercase, hyphenated form this application
     * derives for hostnames and databases, so it needs no sanitizing to be safe
     * in a filename.
     *
     * Falls back to a bare name outside campaign context. Nothing reaches this
     * from there — the route is a campaign route — but a filename is not the
     * place to raise about it.
     */
    public static function filename(): string
    {
        $slug = tenant('slug');

        $prefix = is_string($slug) && $slug !== '' ? $slug.'-' : '';

        return $prefix.'supporters-'.now()->toDateString().'.csv';
    }

    /**
     * One supporter as the file's cells.
     *
     * A null column becomes an empty cell rather than a placeholder word. The
     * module's whole name design rests on never inventing what a source did not
     * say, and writing "Unknown" into an export would be exactly that
     * fabrication, one step further from the campaign where nobody could tell
     * it from a real value.
     *
     * `created_at` is written in full with its offset rather than as a bare
     * date. A date is recoverable from a timestamp and the timestamp is not
     * recoverable from the date — Step 1's asymmetry, applied on the way out.
     *
     * @return list<string>
     */
    private static function row(Supporter $supporter): array
    {
        return [
            $supporter->name ?? '',
            $supporter->given_name ?? '',
            $supporter->family_name ?? '',
            $supporter->email,
            $supporter->postcode ?? '',
            $supporter->subscription_status->value,
            $supporter->created_at?->toIso8601String() ?? '',
        ];
    }
}
