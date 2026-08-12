<?php

declare(strict_types=1);

namespace App\Districts;

use Countable;
use Generator;
use IteratorAggregate;
use RuntimeException;

/**
 * Which congressional districts each ZIP Code Tabulation Area touches, as one
 * Census publication describes them for one Congress.
 *
 * **Shipped with the code rather than stored in any database (D-34).** Every
 * campaign reads the same file, so every campaign gets the same answer, and the
 * data's vintage is whichever commit is deployed -- an environment running this
 * code has exactly the data the code was tested against, with no load step to
 * forget. A table would have bought an indexed lookup and nothing else: each
 * campaign is a separate PostgreSQL database, and PostgreSQL refuses a query
 * from one to another ("cross-database references are not implemented"), so a
 * supporter can never be joined to a district in one statement wherever the
 * district rows live.
 *
 * **This class is the only code that reads the file**, and it also owns the
 * file's format, so the command that rebuilds it writes through encode() here.
 * That is what keeps a later move to a central table -- the shape to take if
 * district data must ever change between code releases -- a change inside this
 * class rather than a hunt for every reader.
 *
 * **A ZCTA is not a ZIP code (D-31).** It is a Census area approximating where
 * addresses share a ZIP, and a ZIP with no area -- PO-box-only, single-recipient,
 * military -- has none, so `73301` is absent here although the Postal Service
 * delivers to it.
 *
 * **This is the relation, not a claim.** A ZCTA touching three districts is
 * reported with all three. Which district, if any, the product may *claim* for a
 * supporter is D-32's rule -- only a ZCTA wholly inside one -- and nothing here
 * applies it.
 *
 * **The boundaries are the ones the file's Congress was elected on.** The Census
 * Bureau publishes this relation for the 118th and 119th Congress only, and
 * several states redrew their maps after the 119th's file for the 2026 election,
 * so for those states this data can describe districts that are no longer on the
 * ballot (D-43). That is why congress() and publishedOn() exist: a surface using
 * this data has to be able to say which boundaries it used.
 *
 * Reading the shipped file was measured at about 15 ms and 11 MB, paid by each
 * process that reads it. Nothing is memoised, because nothing reads it yet.
 *
 * @implements IteratorAggregate<string, list<string>>
 */
final class ZctaDistricts implements Countable, IteratorAggregate
{
    /**
     * The Congress whose relation this release reads.
     *
     * Files are named for their Congress, so a later Congress's file can be
     * built beside this one, and moving to it is a deliberate one-line change
     * here rather than something a rebuild does on its own.
     */
    public const int SHIPPED_CONGRESS = 119;

    /**
     * @param  array<array-key, list<string>>  $zctas  Keyed by ZCTA. Keys are
     *                                                 array-key because PHP turns a
     *                                                 numeric string key such as
     *                                                 "90210" into an integer.
     */
    private function __construct(
        private readonly int $congress,
        private readonly string $publishedOn,
        private readonly string $source,
        private readonly string $sourceSha256,
        private readonly array $zctas,
    ) {}

    /**
     * The relation this release ships.
     */
    public static function shipped(): self
    {
        return self::read(resource_path(self::fileFor(self::SHIPPED_CONGRESS)));
    }

    /**
     * Where a Congress's relation lives, relative to the resources directory.
     */
    public static function fileFor(int $congress): string
    {
        return "data/cd{$congress}-zcta.json";
    }

    /**
     * Read a relation written by encode().
     *
     * Refuses anything that is not in that shape rather than reading around it,
     * because a file that half-parses would answer "touches no district" for
     * every ZCTA it lost, and that is indistinguishable from a real answer.
     */
    public static function read(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("No district data at {$path}.");
        }

        $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! is_int($data['congress'] ?? null)
            || ! is_string($data['published'] ?? null)
            || ! is_string($data['source'] ?? null)
            || ! is_string($data['source_sha256'] ?? null)
            || ! is_array($data['zctas'] ?? null)) {
            throw new RuntimeException("{$path} is not district data in the shape encode() writes.");
        }

        /** @var array<array-key, list<string>> $zctas */
        $zctas = $data['zctas'];

        return new self($data['congress'], $data['published'], $data['source'], $data['source_sha256'], $zctas);
    }

    /**
     * Write a relation in the shape read() accepts.
     *
     * The layout is Prettier's own for this JSON -- one ZCTA per line -- because
     * `npm run format:check` checks everything under resources/, and because a
     * rebuild from a later Census file then shows up as one changed line per
     * ZCTA that moved, which is the diff somebody reviewing a new vintage needs.
     *
     * @param  array<array-key, list<string>>  $zctas  Keyed by ZCTA, in the order
     *                                                 to write them.
     */
    public static function encode(int $congress, string $publishedOn, string $source, string $sourceSha256, array $zctas): string
    {
        $lines = [];

        foreach ($zctas as $zcta => $districts) {
            $lines[] = '        '.self::json((string) $zcta).': ['.implode(', ', array_map(self::json(...), $districts)).']';
        }

        return "{\n"
            .'    "congress": '.$congress.",\n"
            .'    "published": '.self::json($publishedOn).",\n"
            .'    "source": '.self::json($source).",\n"
            .'    "source_sha256": '.self::json($sourceSha256).",\n"
            ."    \"zctas\": {\n"
            .implode(",\n", $lines)."\n"
            ."    }\n"
            ."}\n";
    }

    /**
     * The Congress whose districts these are.
     */
    public function congress(): int
    {
        return $this->congress;
    }

    /**
     * When the Census Bureau published the file this was built from, as Y-m-d.
     */
    public function publishedOn(): string
    {
        return $this->publishedOn;
    }

    /**
     * The name of the Census file this was built from.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * The SHA-256 of the Census file this was built from, which identifies it
     * where a file name cannot.
     */
    public function sourceSha256(): string
    {
        return $this->sourceSha256;
    }

    /**
     * Every district the given ZCTA touches, in GEOID order, or none if the
     * Census file has no such ZCTA.
     *
     * @return list<string> District GEOIDs: a two-digit state code then a
     *                      two-digit district -- `00` for an at-large seat,
     *                      `98` for a delegate or resident commissioner, and
     *                      `ZZ` for an area the Census assigns to no district.
     */
    public function districtsTouching(string $zcta): array
    {
        return $this->zctas[$zcta] ?? [];
    }

    /**
     * How many ZCTAs the relation covers.
     */
    public function count(): int
    {
        return count($this->zctas);
    }

    /**
     * Every ZCTA with the districts it touches.
     *
     * @return Generator<string, list<string>>
     */
    public function getIterator(): Generator
    {
        foreach ($this->zctas as $zcta => $districts) {
            yield (string) $zcta => $districts;
        }
    }

    private static function json(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
