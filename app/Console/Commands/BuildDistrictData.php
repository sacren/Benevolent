<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Districts\ZctaDistricts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use SplFileObject;

/**
 * Rebuilds the shipped district data from a Census ZCTA-to-district file.
 *
 * Run by a person, once per Census publication, against a file they downloaded
 * themselves -- `tab20_cd11920_zcta520_natl.txt` for the 119th Congress, from
 * the Census Bureau's `rel2020/cd-sld/` directory, over an anonymous GET (D-31).
 * The application itself never touches the network for this: the output is
 * committed, so what a deployment holds is what was reviewed.
 *
 * **Not a job, and not scheduled.** It reads one local file and writes one local
 * file in under a second -- 0.8 s for the 119th Congress's file, measured end
 * to end -- so there is nothing to queue (deferral 22's trigger is the first
 * *job* belonging to no module). And a Census publication arrives once a
 * Congress rather than on a clock, which is why the honest record of freshness
 * is the vintage written into the file rather than a schedule entry that
 * nothing runs.
 *
 * **It refuses rather than guesses.** A column it cannot find, a ZCTA that is not
 * five digits, or a district GEOID it does not recognise stops the build with
 * nothing written, because the output is read as the product's whole knowledge
 * of where districts are: a row silently skipped is a ZCTA that "touches no
 * district", which reads exactly like a real answer.
 *
 * The publication date is asked for rather than inferred, because the file does
 * not carry one; it is the `Last-Modified` the Census Bureau serves it with.
 */
#[Signature('districts:build {file : A Census ZCTA-to-congressional-district relationship file} {--published= : The date the Census Bureau published it, as YYYY-MM-DD} {--output= : Where to write the result; defaults to the shipped location for its Congress}')]
#[Description('Rebuild the shipped ZCTA-to-congressional-district data from a Census relationship file.')]
class BuildDistrictData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $published = $this->option('published');

        if (! is_string($published) || ! $this->isDate($published)) {
            $this->components->error('Give the date the Census Bureau published the file, as --published=YYYY-MM-DD.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $file = new SplFileObject($path);

        // Columns are found by name rather than position. The Census Bureau writes
        // a UTF-8 byte-order mark before the first one, which is left alone
        // because neither column read here is first.
        $header = explode('|', rtrim((string) $file->fgets(), "\n"));

        $districtColumn = null;
        $congress = null;

        foreach ($header as $index => $name) {
            if (preg_match('/^GEOID_CD(\d+)_20$/', $name, $match) === 1) {
                $districtColumn = $index;
                $congress = (int) $match[1];
            }
        }

        $zctaColumn = array_search('GEOID_ZCTA5_20', $header, true);

        if ($districtColumn === null || $congress === null || $zctaColumn === false) {
            $this->components->error("{$path} has no GEOID_CD…_20 and GEOID_ZCTA5_20 columns, so it is not a relationship file this command understands.");

            return self::FAILURE;
        }

        // Keyed by ZCTA, then by district. PHP turns a numeric string key such as
        // "90210" or "1198" into an integer, which the sorts below compare as
        // strings and ZctaDistricts::encode() writes back as one.
        /** @var array<array-key, array<array-key, true>> $touching */
        $touching = [];
        $lineNumber = 1;

        while (! $file->eof()) {
            $line = rtrim((string) $file->fgets(), "\n");
            $lineNumber++;

            if ($line === '') {
                continue;
            }

            $fields = explode('|', $line);
            $zcta = $fields[$zctaColumn] ?? '';
            $district = $fields[$districtColumn] ?? '';

            // A row with no ZCTA describes part of a district's area that falls in
            // no ZCTA at all, so it relates the district to nothing.
            if ($zcta === '') {
                continue;
            }

            if (preg_match('/^\d{5}$/', $zcta) !== 1 || preg_match('/^\d{2}(\d{2}|ZZ)$/', $district) !== 1) {
                $this->components->error("Line {$lineNumber} relates ZCTA \"{$zcta}\" to district \"{$district}\", which is not the shape this file should have. Nothing was written.");

                return self::FAILURE;
            }

            $touching[$zcta][$district] = true;
        }

        if ($touching === []) {
            $this->components->error("{$path} relates no ZCTA to any district. Nothing was written.");

            return self::FAILURE;
        }

        ksort($touching, SORT_STRING);

        $zctas = array_map(function (array $districts): array {
            $geoids = array_map('strval', array_keys($districts));
            sort($geoids, SORT_STRING);

            return $geoids;
        }, $touching);

        $output = $this->option('output');
        $output = is_string($output) && $output !== '' ? $output : resource_path(ZctaDistricts::fileFor($congress));

        File::ensureDirectoryExists(dirname($output));
        File::put($output, ZctaDistricts::encode($congress, $published, basename($path), (string) hash_file('sha256', $path), $zctas));

        $pairs = array_sum(array_map(count(...), $zctas));

        $this->components->info(sprintf('Wrote %d ZCTAs and %d ZCTA-district pairs for Congress %d to %s.', count($zctas), $pairs, $congress, $output));

        return self::SUCCESS;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
