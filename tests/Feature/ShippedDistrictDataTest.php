<?php

declare(strict_types=1);

use App\Districts\ZctaDistricts;

/**
 * The district data this release ships, checked against the Census file it was
 * built from.
 *
 * **Every expected number here was measured from the Census file itself, with
 * awk, and not from the command that wrote the shipped data** -- a check that
 * took its expectations from the writer would agree with the writer whatever the
 * writer did. The same measurement compared all 40,147 pairs one for one against
 * the shipped file before it was committed. What this file keeps is the part of
 * that comparison that can run without the 6.2 MB source: the publication it came
 * from, the totals, the shape of every entry, and the ZCTAs this repository's
 * own fixtures use.
 */
test('it is the 119th Congress relation the Census Bureau published on 2024-10-24', function (): void {
    $districts = ZctaDistricts::shipped();

    expect($districts->congress())->toBe(119)
        ->and($districts->publishedOn())->toBe('2024-10-24')
        ->and($districts->source())->toBe('tab20_cd11920_zcta520_natl.txt')
        ->and($districts->sourceSha256())->toBe('57fad59f65af5179ddd18dcfb8f72482dc0cf04fe26e2b9b2b34c51c04405f77');
});

test('it holds every ZCTA and every pair the Census file relates, and nothing malformed', function (): void {
    $districts = ZctaDistricts::shipped();

    $pairs = 0;
    $geoids = [];
    $malformed = [];

    foreach ($districts as $zcta => $touching) {
        $pairs += count($touching);

        if (preg_match('/^\d{5}$/', $zcta) !== 1 || $touching === [] || $touching !== array_values(array_unique($touching))) {
            $malformed[] = $zcta;
        }

        $sorted = $touching;
        sort($sorted, SORT_STRING);

        if ($sorted !== $touching) {
            $malformed[] = $zcta;
        }

        foreach ($touching as $geoid) {
            if (preg_match('/^\d{2}(\d{2}|ZZ)$/', $geoid) !== 1) {
                $malformed[] = $zcta;
            }

            $geoids[$geoid] = true;
        }
    }

    // 33,791 distinct ZCTAs in 40,147 pairs, touching 443 district GEOIDs: the
    // 441 real districts and delegate seats, and two of the three "not defined"
    // `ZZ` areas -- the third touches no ZCTA.
    expect($districts)->toHaveCount(33791)
        ->and($pairs)->toBe(40147)
        ->and($geoids)->toHaveCount(443)
        // And the reader's own list of them, which a seat is checked against,
        // is exactly the set just counted from the relation itself.
        ->and($districts->districts())->toBe(collect(array_keys($geoids))->map(fn ($geoid): string => (string) $geoid)->sort(SORT_STRING)->values()->all())
        ->and($malformed)->toBe([]);
});

test('it relates the ZCTAs this repository\'s fixtures use to the districts the Census file does', function (string $zcta, array $expected): void {
    expect(ZctaDistricts::shipped()->districtsTouching($zcta))->toBe($expected);
})->with([
    // Split three ways, one of them by a 21,754 m² sliver -- the case D-32 exists
    // to refuse to guess.
    'a ZCTA touching three districts' => ['90210', ['0630', '0632', '0636']],
    'a ZCTA touching two districts' => ['02139', ['2505', '2507']],
    'a ZCTA split by a 1,543 m² sliver' => ['90211', ['0636', '0637']],
    'a ZCTA wholly inside one district' => ['90232', ['0637']],
    'another wholly inside one' => ['02141', ['2507']],
    'a third' => ['60601', ['1707']],
    // A real ZIP the Postal Service delivers to that has no area and so no ZCTA.
    'a ZIP with no ZCTA at all' => ['73301', []],
    // What Excel makes of 02139 (D-42): no such ZCTA, so nothing.
    'a ZIP that has lost its leading zero' => ['2139', []],
]);
