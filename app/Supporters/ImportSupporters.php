<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\Supporter;
use App\Models\SupporterImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Reads an uploaded list into the campaign's supporters.
 *
 * **The application's first queued job, and it extends nothing.** D-6 asked
 * whether this codebase owes a tenant-aware base job class. It does not:
 * Stancl's QueueTenancyBootstrapper stamps the campaign into the payload as the
 * job is queued and re-enters it before the job runs, and Phase 0 Step 13
 * measured that a job so restored gets its own database, mail sender, link
 * host, passkey relying party and password broker. Measured again for this
 * job's exact shape, it also gets its own storage tree, and its failed() hook
 * still runs inside the campaign. A base class has nothing left to carry, and
 * an abstract class plus a test that dispatches it asserts only that the queue
 * works. So the job sits with its module rather than in an app/Jobs directory:
 * nothing discovers a job by path, so its location is filing, and the filing
 * rule here is that a module's code goes under the module's name.
 *
 * **This job carries an identifier, never rows, and that is a data-protection
 * decision rather than a style one.** Measured against the real central `jobs`
 * table: a model property is serialized as a ModelIdentifier -- class, id and
 * connection -- so the supporter's address never appears in the payload, while
 * a plain string property is written into it verbatim. Every failure copies its
 * payload into central `failed_jobs`, which is outside the campaign and which
 * Step 11's personal-data inventory does not list. Constructed with parsed rows
 * instead, this job would spill a campaign's supporters into a central table on
 * every failure. Keep it to the record.
 *
 * **Deliberately not ShouldBeUnique and not WithoutOverlapping.** Two operators
 * importing two files at once is fine -- the writes are upserts keyed on the
 * address. And the harm in adding one is specific and silent: both middlewares
 * resolve the container's cache repository, which escapes the tenancy package's
 * per-campaign tagging entirely (measured three separate times in this project),
 * and a lock name built from an id drawn from this campaign's own database --
 * every campaign's ids restart at 1 -- would be one lock shared by every
 * campaign, discarding one campaign's import at dispatch with no failure and no
 * row. If uniqueness is ever genuinely needed, the campaign goes in the *key*,
 * never in the container.
 */
final class ImportSupporters implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many rows are read and written at a time.
     *
     * Bounds the memory a file of any size costs and bounds each round trip,
     * and it is also the granularity of the record's progress, since the counts
     * are written once per chunk rather than once at the end.
     */
    private const int CHUNK = 500;

    public function __construct(private readonly SupporterImport $import) {}

    public function handle(): void
    {
        $mapping = $this->import->columnMapping();

        if (! $mapping instanceof ColumnMapping) {
            // Dispatched without a usable mapping. Only reachable if something
            // queued the job around the surface that collects one, so it says
            // what is wrong rather than importing a file's worth of rows keyed
            // on nothing.
            throw new RuntimeException('This import has no column mapping to read the file with.');
        }

        $path = $this->import->stored_path;

        if ($path === null) {
            // The uploaded file has been pruned (supporters:prune-import-files),
            // which happens a week after it arrived. Reachable only if the queue
            // has not been worked for that long, and said in a sentence rather
            // than left to surface as a read error, because the two have
            // different remedies: this one is not about the file at all.
            //
            // Nulled rather than merely missing is what makes this answerable.
            // A record still naming a path that no longer exists could not tell
            // "we removed it on purpose" from "something ate it".
            throw new RuntimeException('The uploaded list for this import is no longer held; upload it again.');
        }

        $this->import->forceFill([
            'status' => ImportStatus::Running,
            'rows_read' => 0,
            'supporters_added' => 0,
            'supporters_updated' => 0,
            'rows_skipped' => 0,
            'failure_reason' => null,
        ])->save();

        foreach (SupporterFile::rowChunks($path, self::CHUNK) as $rows) {
            $this->absorb($rows, $mapping);
        }

        $this->import->forceFill([
            'status' => ImportStatus::Completed,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Record why the run stopped, where the operator who started it can read it.
     *
     * Written into the *campaign's* record rather than left to central
     * `failed_jobs`, which carries no campaign column and which no campaign
     * surface reads. This hook still runs in campaign context -- measured; it
     * is the JobFailed listener writing the central row that runs after tenancy
     * reverts, not this -- so the write lands in the campaign's own database.
     *
     * Whatever was imported before the failure stays imported. Unwinding would
     * mean holding one transaction open across a file of any size and throwing
     * away thousands of correctly read supporters over one bad row near the end.
     */
    public function failed(Throwable $exception): void
    {
        $this->import->forceFill([
            'status' => ImportStatus::Failed,
            'failure_reason' => $exception->getMessage(),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Turn one chunk of file rows into supporters.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function absorb(array $rows, ColumnMapping $mapping): void
    {
        $read = count($rows);
        $skipped = 0;
        $candidates = [];

        foreach ($rows as $row) {
            $supporter = $this->supporterFrom($row, $mapping);

            if ($supporter === null) {
                $skipped++;

                continue;
            }

            // **Folded within the chunk, because otherwise a real file kills the
            // import.** Two rows differing only in the case of the address are
            // ordinary in an export, and PostgreSQL refuses an upsert whose own
            // batch collides with itself: "ON CONFLICT DO UPDATE command cannot
            // affect row a second time" (SQLSTATE 21000), which aborts the whole
            // chunk rather than one row. Keyed the way the unique index matches,
            // so the fold and the constraint cannot disagree, and the last
            // occurrence wins for the same reason a later file wins over an
            // earlier one: it is the more recent thing the campaign was told.
            $candidates[mb_strtolower($supporter['email'])] = $supporter;
        }

        $written = $candidates === [] ? ['added' => 0, 'updated' => 0] : $this->write($candidates);

        // Incremented rather than assigned, so anything reading the record
        // watches the file being consumed rather than seeing it jump from
        // nothing to everything at the end.
        $this->import->increment('rows_read', $read);
        $this->import->increment('supporters_added', $written['added']);
        $this->import->increment('supporters_updated', $written['updated']);
        $this->import->increment('rows_skipped', $skipped);
    }

    /**
     * @param  array<string, array<string, string|null>>  $candidates  keyed by folded address
     * @return array{added: int, updated: int}
     */
    private function write(array $candidates): array
    {
        // Asked before the write rather than inferred from an affected-row
        // count, which cannot tell an insert from an update. "We added 900 and
        // corrected 3,100" and the reverse are very different things to have
        // done to a list, and an operator deciding whether the import did what
        // they meant is reading exactly that difference.
        // Guarded like the write below it, and for the same reason rather than
        // out of caution: its bindings *are* the addresses.
        $existing = $this->withoutNamingAnybody(fn (): array => Supporter::query()
            ->whereRaw('lower(email) in ('.implode(',', array_fill(0, count($candidates), '?')).')',
                array_keys($candidates))
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower($email))
            ->all());

        $updated = count(array_intersect(array_keys($candidates), $existing));

        $now = now();

        $values = array_map(
            fn (array $supporter): array => [
                ...$supporter,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_values($candidates),
        );

        $this->withoutNamingAnybody(fn () => Supporter::query()->upsert(
            $values,
            // The constraint as the index actually expresses it (D-8). A plain
            // `email` here would name no unique index at all and PostgreSQL
            // would refuse the statement.
            [new Expression('lower(email)')],
            $this->updateList(),
        ));

        return ['added' => count($candidates) - $updated, 'updated' => $updated];
    }

    /**
     * Run a statement whose bindings are people, so that its failure cannot
     * repeat them.
     *
     * **A database error carries the rows that caused it, and those rows are
     * people.** Measured against the real central `failed_jobs` table: a
     * QueryException's message inlines every binding into the SQL it prints, so
     * one row the database refused sent a supporter's name and email address
     * out of the campaign and into a central table in the clear -- while the
     * payload beside it stayed clean, which is what made this easy to miss. The
     * job was built to carry an identifier rather than rows precisely to keep
     * personal data out of there, and this was the second door.
     *
     * Both of this class's statements go through here, not just the write. The
     * select that counts who is already on the list binds the addresses too,
     * and a fix covering only the obvious statement would have left the
     * question open one line above it.
     *
     * Rethrown *without* chaining, deliberately: attaching the original as
     * `previous` would put its message straight back into the string the
     * failure is recorded as, which is the whole of what this prevents. What
     * survives is what anybody actually debugs from -- the driver's own
     * complaint, naming the SQLSTATE and the constraint or column at fault,
     * with no values in it.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $statement
     * @return TResult
     */
    private function withoutNamingAnybody(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (QueryException $exception) {
            throw new RuntimeException(sprintf(
                'The list could not be written: %s',
                $exception->getPrevious()?->getMessage() ?? 'the database refused the write.',
            ));
        }
    }

    /**
     * What an import is allowed to change about a supporter it already has.
     *
     * Three deliberate absences and one deliberate shape, each measured.
     *
     * **`email` is absent**, so a later file's casing never overwrites the
     * casing already stored. D-8 stores the address exactly as given, the index
     * matches on `lower(email)`, and the first spelling the campaign recorded is
     * the one it keeps -- which also means an operator's hand correction on the
     * edit form is not undone by the next upload.
     *
     * **`subscription_status` is absent**, and this is the one that matters
     * most. Somebody who asked not to be contacted stays that way when a later
     * file says otherwise; measured, an incoming row saying `subscribed` left an
     * unsubscribed supporter unsubscribed. That is the whole reason unsubscribing
     * is a status rather than a deletion, and an import that could reverse it
     * would make the record worthless.
     *
     * **`created_at` is absent**, so an existing supporter keeps the day they
     * joined the list rather than the day a file was re-uploaded.
     *
     * And every column that *is* here is COALESCE-shaped, so a blank cell means
     * *the file did not say* and never *forget what you knew*. Measured both
     * ways: a plain update list wiped a stored name and postcode to null when
     * the incoming row's cells were empty, and this shape left them intact while
     * still applying the values the file did supply. It is Step 1's asymmetry
     * arriving at the importer -- what the source told us is kept, and nothing
     * is invented or destroyed on its behalf.
     *
     * Written out column by column rather than built from a loop, because the
     * expressions have to be literal strings: they are SQL, they are the whole
     * of the rule above, and a reader checking that `email` and
     * `subscription_status` really are absent should be able to see the list
     * rather than reconstruct it from a generator.
     *
     * @return array<string, ExpressionContract>
     */
    private function updateList(): array
    {
        return [
            'name' => DB::raw('coalesce(excluded.name, supporters.name)'),
            'given_name' => DB::raw('coalesce(excluded.given_name, supporters.given_name)'),
            'family_name' => DB::raw('coalesce(excluded.family_name, supporters.family_name)'),
            'postcode' => DB::raw('coalesce(excluded.postcode, supporters.postcode)'),
            'updated_at' => DB::raw('excluded.updated_at'),
        ];
    }

    /**
     * One file row as a supporter, or nothing if it does not describe one.
     *
     * A row with no address is skipped rather than refused, and skipping is
     * counted and shown: the address is the identity (D-8) and there is no
     * second channel to reach somebody by, so a row without one names nobody.
     * Refusing the whole file over it would throw away every other row in an
     * export that happens to end with a summary line.
     *
     * @param  array<string, string>  $row
     * @return array<string, string|null>|null
     */
    private function supporterFrom(array $row, ColumnMapping $mapping): ?array
    {
        $email = trim($row[$mapping->email] ?? '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $name = $this->cell($row, $mapping->name);
        $givenName = $this->cell($row, $mapping->givenName);
        $familyName = $this->cell($row, $mapping->familyName);

        return match ($mapping->nameMode) {
            // What the source gave, stored as it gave it. Both parts stay null,
            // meaning we were never told where the boundary falls.
            NameColumnMode::Single => [
                'name' => $name,
                'given_name' => null,
                'family_name' => null,
                'email' => $email,
                'postcode' => $this->cell($row, $mapping->postcode),
            ],

            // The parts are recorded as given and the display string is the join
            // of them -- a presentation decision, recomputable because the parts
            // are kept, which is why the schema keeps them.
            NameColumnMode::Split => [
                'name' => $this->join($givenName, $familyName),
                'given_name' => $givenName,
                'family_name' => $familyName,
                'email' => $email,
                'postcode' => $this->cell($row, $mapping->postcode),
            ],

            NameColumnMode::None => [
                'name' => null,
                'given_name' => null,
                'family_name' => null,
                'email' => $email,
                'postcode' => $this->cell($row, $mapping->postcode),
            ],
        };
    }

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }

        $value = trim($row[$column] ?? '');

        // Empty means the file did not say, which the update list above turns
        // into "keep what we knew" rather than into a blank.
        return $value === '' ? null : $value;
    }

    /**
     * The display name for a source that gave the parts separately.
     *
     * Given name first, which is a presentation choice this application makes
     * and can revisit at no cost, because the parts it was told are stored
     * beside it. A row carrying only one of the two still gets that one as its
     * display name rather than a string with a stray space in it.
     */
    private function join(?string $givenName, ?string $familyName): ?string
    {
        $joined = trim(implode(' ', array_filter([$givenName, $familyName])));

        return $joined === '' ? null : $joined;
    }
}
