<?php

declare(strict_types=1);

use App\Districts\ZctaDistricts;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The command that rebuilds the shipped district data from a Census file.
 *
 * Driven over a few real rows of the 119th Congress's relationship file rather
 * than invented ones, chosen because each exercises something the full file
 * does: a ZCTA split three ways and supplied out of order (`90210`), a ZCTA
 * touching the Census's "not defined" pseudo-district `09ZZ` by water alone
 * (`06437`), the delegate district a DC ZIP belongs to (`1198`), and a row that
 * describes a district's area with no ZCTA at all. The byte-order mark the
 * Census Bureau writes before the header is kept too, so the sample is the
 * file's real shape rather than a tidied one.
 *
 * What the command writes is read back through ZctaDistricts, the one class the
 * application reads that data through -- so these tests also hold the writer
 * and the reader to the same format.
 */
const CENSUS_HEADER = 'OID_CD119_20|GEOID_CD119_20|NAMELSAD_CD119_20|AREALAND_CD119_20|AREAWATER_CD119_20|MTFCC_CD119_20|FUNCSTAT_CD119_20|OID_ZCTA5_20|GEOID_ZCTA5_20|NAMELSAD_ZCTA5_20|AREALAND_ZCTA5_20|AREAWATER_ZCTA5_20|MTFCC_ZCTA5_20|CLASSFP_ZCTA5_20|FUNCSTAT_ZCTA5_20|AREALAND_PART|AREAWATER_PART';

const CENSUS_ROWS = [
    '2119035951820645|0636|Congressional District 36|251540870|252514261|G5200|N|221704258470394|90210|ZCTA5 90210|27823432|153478|G6350|B5|S|10523386|2279',
    '2119035951820634|0630|Congressional District 30|464384391|2017004|G5200|N|221704258470394|90210|ZCTA5 90210|27823432|153478|G6350|B5|S|21754|0',
    '2119035951820684|09ZZ|Congressional Districts not defined|0|1095660734|G5200|F|221704257530392|06437|ZCTA5 06437|122036796|4075183|G6350|B5|S|0|1452875',
    '2119035781135773|0101|Congressional District 1|18752973689|2274743296|G5200|N|||||||||558858619|1794806659',
    '2119035951820679|0903|Congressional District 3|1227265623|70523120|G5200|N|221704257530392|06437|ZCTA5 06437|122036796|4075183|G6350|B5|S|122036796|2622308',
    '2119035963374576|1198|Delegate District (at Large)|158316124|18709762|G5200|N|221704257714557|20001|ZCTA5 20001|5302364|159887|G6350|B5|S|5302364|159887',
    '2119035951820641|0632|Congressional District 32|764902124|241616060|G5200|N|221704258470394|90210|ZCTA5 90210|27823432|153478|G6350|B5|S|17278292|151199',
];

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/districts-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($this->directory);

    $this->source = $this->directory.'/tab20_cd11920_zcta520_natl.txt';
    $this->output = $this->directory.'/out/cd119-zcta.json';
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

/**
 * @param  list<string>  $rows
 */
function writeCensusFile(string $path, array $rows, string $header = CENSUS_HEADER): void
{
    File::put($path, "\u{FEFF}".$header."\n".implode("\n", $rows)."\n");
}

test('it writes the relation a Census file describes, which the reader reads back', function (): void {
    writeCensusFile($this->source, CENSUS_ROWS);

    $this->artisan('districts:build', [
        'file' => $this->source,
        '--published' => '2024-10-24',
        '--output' => $this->output,
    ])->assertSuccessful();

    $districts = ZctaDistricts::read($this->output);

    // The Congress comes from the district column's own name, so a later
    // Census file carries its Congress into the output without being told.
    expect($districts->congress())->toBe(119)
        ->and($districts->publishedOn())->toBe('2024-10-24')
        ->and($districts->source())->toBe('tab20_cd11920_zcta520_natl.txt')
        ->and($districts->sourceSha256())->toBe(hash_file('sha256', $this->source))
        // Three ZCTAs: the district-only row relates its district to nothing.
        ->and($districts)->toHaveCount(3)
        // Supplied 0636, 0630, 0632; written in GEOID order.
        ->and($districts->districtsTouching('90210'))->toBe(['0630', '0632', '0636'])
        // Touched by 09ZZ through water alone, and kept: this is the relation as
        // published, and what to claim from it is D-32's rule, applied later.
        ->and($districts->districtsTouching('06437'))->toBe(['0903', '09ZZ'])
        ->and($districts->districtsTouching('20001'))->toBe(['1198'])
        // A leading zero survives, because a ZCTA is text rather than a number.
        ->and(iterator_to_array($districts))->toHaveKeys(['06437', '20001', '90210']);
});

test('it writes the layout the front-end formatter checks, one ZCTA per line', function (): void {
    writeCensusFile($this->source, CENSUS_ROWS);

    $this->artisan('districts:build', [
        'file' => $this->source,
        '--published' => '2024-10-24',
        '--output' => $this->output,
    ])->assertSuccessful();

    // `npm run format:check` runs Prettier over resources/, where the shipped
    // file lives, so this is Prettier's own layout for it, checked there against
    // the real file. Pinned here as well so that a change to the writer is seen
    // by the suite rather than first by the formatter on the next rebuild.
    expect(File::get($this->output))->toBe(implode("\n", [
        '{',
        '    "congress": 119,',
        '    "published": "2024-10-24",',
        '    "source": "tab20_cd11920_zcta520_natl.txt",',
        '    "source_sha256": "'.hash_file('sha256', $this->source).'",',
        '    "zctas": {',
        '        "06437": ["0903", "09ZZ"],',
        '        "20001": ["1198"],',
        '        "90210": ["0630", "0632", "0636"]',
        '    }',
        '}',
        '',
    ]));
});

test('it refuses a file or a date it does not understand, and writes nothing', function (array $rows, string $header, ?string $published): void {
    writeCensusFile($this->source, $rows, $header);

    $arguments = ['file' => $this->source, '--output' => $this->output];

    if ($published !== null) {
        $arguments['--published'] = $published;
    }

    $this->artisan('districts:build', $arguments)->assertFailed();

    expect(File::exists($this->output))->toBeFalse();
})->with([
    'no publication date' => [CENSUS_ROWS, CENSUS_HEADER, null],
    'a publication date that is not a date' => [CENSUS_ROWS, CENSUS_HEADER, '2024-13-01'],
    'a relationship file for state legislative districts instead' => [
        CENSUS_ROWS,
        str_replace('_CD119_20', '_SLDL2024_20', CENSUS_HEADER),
        '2024-10-24',
    ],
    'a ZCTA that has lost its leading zero' => [
        [...CENSUS_ROWS, '2119035951820679|0903|Congressional District 3|1227265623|70523120|G5200|N|x|6437|ZCTA5 06437|1|1|G6350|B5|S|1|1'],
        CENSUS_HEADER,
        '2024-10-24',
    ],
    'a district GEOID of the wrong shape' => [
        [...CENSUS_ROWS, '2119035951820679|093|Congressional District 3|1227265623|70523120|G5200|N|x|06437|ZCTA5 06437|1|1|G6350|B5|S|1|1'],
        CENSUS_HEADER,
        '2024-10-24',
    ],
    'no ZCTA related to any district' => [
        ['2119035781135773|0101|Congressional District 1|18752973689|2274743296|G5200|N|||||||||558858619|1794806659'],
        CENSUS_HEADER,
        '2024-10-24',
    ],
]);

test('the reader refuses a file that is not in the shape the command writes', function (?string $contents, string $exception, string $message): void {
    $path = $this->directory.'/cd119-zcta.json';

    if ($contents !== null) {
        File::put($path, $contents);
    }

    // A file that half-parsed would answer "touches no district" for every ZCTA
    // it lost, which is indistinguishable from a real answer -- so it is refused
    // outright rather than read around.
    //
    // The class and the message are both pinned, and that is measured rather
    // than fussy: with the reader's own shape check deleted, a file missing its
    // relation still throws, because the framework turns PHP's undefined-key
    // warning into an ErrorException -- so "it throws" alone passed three of
    // these four cases against a reader that checked nothing. What is asserted
    // is that the refusal is the reader's, and says what is wrong.
    expect(fn () => ZctaDistricts::read($path))->toThrow($exception, $message);
})->with([
    'no file at all' => [null, RuntimeException::class, 'No district data at'],
    'not JSON' => ['congress: 119', JsonException::class, 'Syntax error'],
    'JSON without the relation' => [
        '{"congress": 119, "published": "2024-10-24", "source": "x", "source_sha256": "y"}',
        RuntimeException::class,
        'is not district data in the shape encode() writes',
    ],
    'a Congress that is not a number' => [
        '{"congress": "119", "published": "2024-10-24", "source": "x", "source_sha256": "y", "zctas": {}}',
        RuntimeException::class,
        'is not district data in the shape encode() writes',
    ],
]);

test('it refuses a file that is not there', function (): void {
    $this->artisan('districts:build', [
        'file' => $this->directory.'/missing.txt',
        '--published' => '2024-10-24',
        '--output' => $this->output,
    ])->assertFailed();

    expect(File::exists($this->output))->toBeFalse();
});
