<?php

declare(strict_types=1);

use App\Districts\Seat;
use App\Districts\ZctaDistricts;

/**
 * The name a seat is shown under, for every seat the shipped relation holds.
 *
 * The state table inside Seat was transcribed from the Census Bureau's
 * `state.txt` by script and compared back against it before it was committed.
 * What this file keeps is the part that runs without that file: every seat the
 * relation can produce has a name, no two seats share one, and the names follow
 * the convention people actually use.
 */
test('every seat in the shipped relation has a name, and no two seats share one', function (): void {
    $labels = [];

    foreach (ZctaDistricts::shipped() as $touching) {
        foreach ($touching as $geoid) {
            if (! str_ends_with($geoid, 'ZZ')) {
                $labels[$geoid] = Seat::fromGeoid($geoid)->label();
            }
        }
    }

    // 441: the 435 voting districts and six delegate seats, measured from the
    // Census file rather than from this code. The second count is what notices
    // two state codes transcribed to the same abbreviation.
    expect($labels)->toHaveCount(441)
        ->and(array_unique($labels))->toHaveCount(441);
});

test('a seat is named the way people name it', function (string $geoid, string $label): void {
    expect(Seat::fromGeoid($geoid)->label())->toBe($label);
})->with([
    'a numbered district' => ['0637', 'CA-37'],
    'a single-digit district keeps its zero' => ['4807', 'TX-07'],
    'a state whose code begins with zero' => ['0903', 'CT-03'],
    'an at-large state, district 00' => ['0200', 'AK-AL'],
    'the District of Columbia\'s delegate, district 98' => ['1198', 'DC-AL'],
    'Puerto Rico\'s resident commissioner' => ['7298', 'PR-AL'],
    'a territory\'s delegate' => ['6098', 'AS-AL'],
]);

test('a GEOID that names no seat is refused rather than shown as digits', function (string $geoid, string $message): void {
    expect(fn () => Seat::fromGeoid($geoid))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an area the Census puts in no district' => ['09ZZ', 'is not the GEOID of a congressional district'],
    'a state code with no seat' => ['7401', 'names a state code no seat belongs to'],
    'too short' => ['063', 'is not the GEOID of a congressional district'],
    'a label rather than a GEOID' => ['CA37', 'is not the GEOID of a congressional district'],
]);
