<?php

declare(strict_types=1);

use App\Districts\DistrictAnswer;
use App\Districts\DistrictClaim;
use App\Districts\Seat;
use App\Districts\SeatStanding;
use App\Districts\ZctaDistricts;

/**
 * What the product may say about a supporter's district, from the ZIP it holds.
 *
 * **Exit criterion 3 is the reason this file exists: the product never tells a
 * campaign that somebody is a constituent of a district they are not in.** No
 * later change repairs that failure, so it is guarded twice -- by the answers
 * for the ZIPs this repository's own fixtures use, which are real ZCTAs with
 * known relations, and by a sweep of every ZCTA the shipped relation holds,
 * held to counts measured from the Census data with python rather than from
 * this code.
 *
 * Every other answer is one of the four kinds of "no district named" (D-42),
 * and each is asserted as itself, because a page that reports them all as
 * "unknown" is a page an operator learns to ignore.
 */
beforeEach(function (): void {
    $this->relation = ZctaDistricts::shipped();
});

/**
 * @param  list<Seat>  $seats
 * @return list<string>
 */
function seatLabels(array $seats): array
{
    return array_map(fn (Seat $seat): string => $seat->label(), $seats);
}

test('a ZIP wholly inside one district has that district claimed', function (string $stored, string $zip, string $seat): void {
    $claim = DistrictClaim::for($stored, $this->relation);

    expect($claim->answer)->toBe(DistrictAnswer::Placed)
        ->and($claim->zip)->toBe($zip)
        ->and($claim->claimed()?->label())->toBe($seat)
        ->and(seatLabels($claim->touching))->toBe([$seat]);
})->with([
    'a five-digit ZIP' => ['90232', '90232', 'CA-37'],
    'a ZIP+4 with a hyphen' => ['90232-1234', '90232', 'CA-37'],
    'a ZIP+4 with a space' => ['90232 1234', '90232', 'CA-37'],
    'a ZIP+4 run together' => ['902321234', '90232', 'CA-37'],
    'a ZIP with whitespace around it' => ['  90232 ', '90232', 'CA-37'],
    'a ZIP beginning with zero' => ['02141', '02141', 'MA-07'],
    'a delegate\'s seat' => ['20001', '20001', 'DC-AL'],
    // Both touch an area in no district as well -- Long Island Sound and Lake
    // Michigan -- by water alone, so everybody who lives there is in one seat.
    'a shoreline ZIP beside an area in no district' => ['06437', '06437', 'CT-03'],
    'a lakefront ZIP beside an area in no district' => ['60657', '60657', 'IL-05'],
]);

test('a ZIP whose area crosses a district boundary has no district claimed', function (string $stored, array $touching): void {
    $claim = DistrictClaim::for($stored, $this->relation);

    // The districts it might be in are kept, because they are true and because
    // "one of CA-30, CA-32 or CA-36" tells an operator more than "unknown". What
    // is never produced is one of them as the answer.
    expect($claim->answer)->toBe(DistrictAnswer::Split)
        ->and($claim->claimed())->toBeNull()
        ->and(seatLabels($claim->touching))->toBe($touching);
})->with([
    // One of the three parts is a 21,754 m² sliver; D-32 refuses to guess anyway.
    'a ZIP touching three districts' => ['90210', ['CA-30', 'CA-32', 'CA-36']],
    'the same ZIP written as a ZIP+4' => ['90210-1234', ['CA-30', 'CA-32', 'CA-36']],
    'a ZIP touching two districts' => ['02139', ['MA-05', 'MA-07']],
    // Also touches Lake Michigan's `17ZZ`, which is left out of the list rather
    // than shown as a fourth thing the supporter might be in.
    'a ZIP touching three districts and an area in no district' => ['60615', ['IL-01', 'IL-02', 'IL-07']],
]);

test('a well-formed ZIP the relation has no area for has no district, and says so', function (): void {
    // A real ZIP the Postal Service delivers to, with no Census area at all.
    $claim = DistrictClaim::for('73301', $this->relation);

    expect($claim->answer)->toBe(DistrictAnswer::Unmapped)
        ->and($claim->zip)->toBe('73301')
        ->and($claim->claimed())->toBeNull()
        ->and($claim->touching)->toBe([]);
});

test('a value that is not a ZIP is reported as one, never read as the nearest ZIP', function (string $stored, bool $lostLeadingZero): void {
    $claim = DistrictClaim::for($stored, $this->relation);

    expect($claim->answer)->toBe(DistrictAnswer::Malformed)
        ->and($claim->zip)->toBeNull()
        ->and($claim->claimed())->toBeNull()
        ->and($claim->touching)->toBe([])
        ->and($claim->mayHaveLostLeadingZero())->toBe($lostLeadingZero);
})->with([
    // What a spreadsheet makes of 02139. Padding it back would be a guess; it is
    // reported, with the likely cause, for somebody to correct.
    'a ZIP that has lost its leading zero' => ['2139', true],
    'six digits' => ['902101', false],
    // The segment matcher reaches this value through `90210`; a district is not
    // claimed from it, because nothing about it is a ZIP.
    'a ZIP with something after it' => ['90210abc', false],
    'a ZIP+4 missing digits' => ['90210-12', false],
    'a word' => ['banana', false],
    // A line break is not folded away, so it is part of the value, and a value
    // with one in it is not a ZIP -- which is also what PostgreSQL's `$` says
    // of it, so this check and the same check asked of the database agree.
    'a ZIP with a line break after it' => ["02141\n", false],
    'a ZIP+4 with a line break after it' => ["02141-1234\n", false],
    'four digits with a line break after them' => ["2139\n", false],
]);

test('no ZIP at all is its own answer', function (?string $stored): void {
    $claim = DistrictClaim::for($stored, $this->relation);

    expect($claim->answer)->toBe(DistrictAnswer::Missing)
        ->and($claim->zip)->toBeNull()
        ->and($claim->claimed())->toBeNull()
        ->and($claim->mayHaveLostLeadingZero())->toBeFalse();
})->with([
    'nothing stored' => [null],
    'an empty string' => [''],
    'only spaces' => ['   '],
]);

test('across every ZCTA the relation holds, a district is claimed only where one district holds its whole area', function (): void {
    $claimed = 0;
    $split = 0;
    $claimedAcrossABoundary = [];

    foreach ($this->relation as $zcta => $touching) {
        $claim = DistrictClaim::for($zcta, $this->relation);
        $districts = array_values(array_filter($touching, fn (string $geoid): bool => ! str_ends_with($geoid, 'ZZ')));

        if ($claim->claimed() !== null) {
            $claimed++;

            if ($districts !== [$claim->claimed()->geoid]) {
                $claimedAcrossABoundary[] = $zcta;
            }
        }

        if ($claim->answer === DistrictAnswer::Split) {
            $split++;
        }
    }

    // 27,929 = the 27,909 ZCTAs inside exactly one district, plus the 20 that
    // touch one district and an area in no district by water alone. 5,862 cross
    // a boundary between real districts. Both measured from the shipped file
    // with python, which never ran this code; together with the list below they
    // sum to all 33,791.
    expect($claimedAcrossABoundary)->toBe([])
        ->and($claimed)->toBe(27929)
        ->and($split)->toBe(5862);
});

test('against the seat a campaign is running for, a supporter is in it, may be in it, or is not in it', function (string $stored, string $seat, ?SeatStanding $standing): void {
    $claim = DistrictClaim::for($stored, $this->relation, Seat::parse($seat, $this->relation));

    expect($claim->standing())->toBe($standing);
})->with([
    'a ZIP wholly inside the seat' => ['02141', 'MA-07', SeatStanding::In],
    // Touches MA-07 and MA-05. The seat is one of the districts it might be in,
    // and D-32's rule holds one district at a time: never "in".
    'a ZIP crossing the seat\'s boundary' => ['02139', 'MA-07', SeatStanding::MaybeIn],
    'a ZIP wholly inside another district' => ['90232', 'MA-07', SeatStanding::NotIn],
    // What knowing the seat adds: this was "not named", and none of it is in the
    // seat, so it is definitely outside it.
    'a ZIP crossing districts that do not include the seat' => ['90210', 'MA-07', SeatStanding::NotIn],
    'the same ZIP against a seat it touches' => ['90210', 'CA-36', SeatStanding::MaybeIn],
    'a ZIP with no district data' => ['73301', 'MA-07', null],
    'a value that is not a ZIP' => ['2139', 'MA-07', null],
    'no ZIP at all' => ['', 'MA-07', null],
]);

test('a campaign with no seat has no standing to compare against', function (): void {
    expect(DistrictClaim::for('02141', $this->relation)->standing())->toBeNull()
        ->and(DistrictClaim::for('02141', $this->relation)->jsonSerialize()['seatStanding'])->toBeNull()
        // The positive half through the same serialisation, so the null above is
        // not a field that is simply never filled.
        ->and(DistrictClaim::for('02141', $this->relation, Seat::parse('MA-07', $this->relation))->jsonSerialize()['seatStanding'])->toBe('in');
});

test('across every ZCTA the relation holds, a supporter is placed in a seat only where the seat holds its whole area', function (): void {
    // D-32's sweep asked one district at a time: against every seat a ZCTA
    // touches, "in" must mean the relation lists that seat and nothing else.
    $inAcrossABoundary = [];
    $in = 0;

    foreach ($this->relation as $zcta => $touching) {
        $districts = array_values(array_filter($touching, fn (string $geoid): bool => ! str_ends_with($geoid, 'ZZ')));

        foreach ($districts as $geoid) {
            $claim = DistrictClaim::for($zcta, $this->relation, Seat::fromGeoid($geoid));

            if ($claim->standing() === SeatStanding::In) {
                $in++;

                if ($districts !== [$geoid]) {
                    $inAcrossABoundary[] = $zcta.' in '.$geoid;
                }
            }
        }
    }

    // One "in" per claimable ZCTA, against its own seat: the 27,929 measured
    // with python for the sweep above.
    expect($inAcrossABoundary)->toBe([])
        ->and($in)->toBe(27929);
});
