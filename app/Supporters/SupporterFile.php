<?php

declare(strict_types=1);

namespace App\Supporters;

use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads an uploaded list off the campaign's own disk.
 *
 * Reading the header row and reading the data rows are separate questions
 * asked at separate moments -- an upload has to know a file's columns before
 * anybody can say what they mean, and only then does anything read the rows --
 * but the two must agree exactly about what a header is and how a row lines up
 * with one. One copy of that is the only way they can, which is why this is a
 * class rather than a few lines inside the job.
 *
 * **CSV only, read with PHP's own parser, and no dependency.** A spreadsheet
 * reader is a `composer require` in service of a format nobody has asked for;
 * every advocacy tool exports CSV, and adding a second format later is additive.
 *
 * The disk is `local`, whose root the filesystem bootstrapper has already
 * pointed at this campaign's own storage tree -- measured as a separate
 * directory per campaign rather than a shared one, so no path in this class is
 * capable of reaching another campaign's upload.
 */
final class SupporterFile
{
    /**
     * The disk uploads are stored on, named once here rather than at each site.
     */
    public const string DISK = 'local';

    /**
     * The byte-order mark a spreadsheet application writes at the head of a
     * UTF-8 export.
     *
     * It is invisible in every editor and it is part of the first header's
     * name as far as a string comparison is concerned, so a mapping naming
     * `Email` would not match the file's own first column and every row would
     * import with no address. Stripped where the header is read, which is the
     * only place it can appear.
     */
    private const string BYTE_ORDER_MARK = "\u{FEFF}";

    /**
     * The file's header row, in the order the file gives it.
     *
     * Blank headers are dropped rather than kept as empty names: a trailing
     * comma on the header line is common and produces a column nobody can name
     * or choose.
     *
     * @return list<string>
     */
    public static function headers(string $path): array
    {
        $stream = self::open($path);

        try {
            $header = fgetcsv($stream);
        } finally {
            fclose($stream);
        }

        if (! is_array($header)) {
            throw new RuntimeException('The file has no header row.');
        }

        return array_values(array_filter(
            array_map(self::normalizeHeader(...), $header),
            fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Every data row, keyed by header, handed over in chunks.
     *
     * A generator rather than an array because the file's size is the
     * operator's choice and a list worth importing does not belong in memory
     * all at once. Chunking is what lets the job report progress as it goes and
     * what bounds each database round trip.
     *
     * A row with fewer cells than the header has is padded rather than refused:
     * exports routinely omit trailing empty fields, and a missing postcode is
     * not a reason to reject somebody the campaign can contact. A row with more
     * cells than headers keeps only the ones a header names, since a value with
     * no column cannot be mapped to anything.
     *
     * @return Generator<int, list<array<string, string>>>
     */
    public static function rowChunks(string $path, int $size): Generator
    {
        $stream = self::open($path);

        try {
            $header = fgetcsv($stream);

            if (! is_array($header)) {
                throw new RuntimeException('The file has no header row.');
            }

            $header = array_map(self::normalizeHeader(...), $header);

            $chunk = [];

            while (($cells = fgetcsv($stream)) !== false) {
                if ($cells === [null]) {
                    // A blank line, which fgetcsv reports as a single null cell
                    // rather than as an empty array. Ordinary at the end of a
                    // file, and not a row.
                    continue;
                }

                $chunk[] = self::combine($header, $cells);

                if (count($chunk) >= $size) {
                    yield $chunk;

                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                yield $chunk;
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $cells
     * @return array<string, string>
     */
    private static function combine(array $header, array $cells): array
    {
        $row = [];

        foreach ($header as $position => $name) {
            if ($name === '') {
                continue;
            }

            $row[$name] = trim((string) ($cells[$position] ?? ''));
        }

        return $row;
    }

    private static function normalizeHeader(?string $name): string
    {
        return trim(str_replace(self::BYTE_ORDER_MARK, '', (string) $name));
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $stream = Storage::disk(self::DISK)->readStream($path);

        if (! is_resource($stream)) {
            // The record says a file is there and the disk disagrees. Saying so
            // is the whole point: the alternative is an import that reads
            // nothing and reports that the file contained no supporters.
            throw new RuntimeException('The uploaded file could not be read.');
        }

        return $stream;
    }
}
