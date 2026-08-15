<?php

declare(strict_types=1);

use App\Districts\DistrictClaim;
use App\Districts\DistrictNarrowing;
use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;

/*
 * A narrowing by district, asked of the database, against the same question
 * asked of DistrictClaim in PHP.
 *
 * **The two have to agree row for row, and this file is where that is held.**
 * DistrictNarrowing writes DistrictClaim's shape check and its claimable ZIP
 * codes into SQL, so there are two spellings of one rule -- the shape
 * D-29 was extracted to prevent -- and the only thing keeping them one rule is
 * a comparison over the values on which they could part: every spelling the
 * fold must reach, and every value that is not a ZIP however much of one it
 * starts with. Each test also names the expected people outright, because two
 * implementations can agree by both being wrong.
 */

/**
 * Stored values chosen to part the two readers if they can be parted, keyed by
 * what each one is.
 *
 * @return array<string, string|null>
 */
function districtCorpus(): array
{
    return [
        'MA-07, five digits' => '02141',
        'MA-07, ZIP+4 with a hyphen' => '02141-1234',
        'MA-07, ZIP+4 with a space' => '02141 1234',
        'MA-07, ZIP+4 run together' => '021411234',
        'MA-07, with spaces around it' => ' 02141 ',
        'MA-07, another ZIP code wholly inside it' => '02115',
        // Touches MA-05 and MA-07: may be in the seat, and never claimed for it.
        'crossing MA-07\'s boundary' => '02139',
        'wholly inside CA-37' => '90232',
        'a ZIP code with no district data' => '73301',
        // Each of these starts with an MA-07 ZIP code. The prefix matcher would
        // reach every one through `02141`; none of them is a ZIP.
        'an MA-07 ZIP code with something after it' => '02141abc',
        'an MA-07 ZIP code with a line break after it' => "02141\n",
        'six digits beginning with an MA-07 ZIP code' => '021415',
        'an MA-07 ZIP+4 missing a digit' => '02141-123',
        'a ZIP code that lost its leading zero' => '2141',
        'a word' => 'banana',
        'nothing stored' => null,
    ];
}

beforeEach(function (): void {
    $this->relation = ZctaDistricts::shipped();
});

test('a narrowing by district reaches exactly the supporters the product would place in it', function (): void {
    $ids = [];

    foreach (districtCorpus() as $what => $postcode) {
        $ids[$what] = Supporter::factory()->create(['postcode' => $postcode])->getKey();
    }

    $seat = Seat::parse('MA-07', $this->relation);

    $narrowed = DistrictNarrowing::apply(Supporter::query(), $seat, $this->relation)->pluck('id')->all();

    $placed = Supporter::query()->get()
        ->filter(fn (Supporter $supporter): bool => DistrictClaim::for($supporter->postcode, $this->relation)->claimed()?->geoid === $seat->geoid)
        ->pluck('id')
        ->all();

    // The six ways the corpus spells a ZIP code wholly inside MA-07, and nothing
    // else: not the ZIP code crossing its boundary, and not the values that only
    // begin with one of its ZIP codes.
    expect($narrowed)->toEqualCanonicalizing([
        $ids['MA-07, five digits'],
        $ids['MA-07, ZIP+4 with a hyphen'],
        $ids['MA-07, ZIP+4 with a space'],
        $ids['MA-07, ZIP+4 run together'],
        $ids['MA-07, with spaces around it'],
        $ids['MA-07, another ZIP code wholly inside it'],
    ])
        // And the database's answer is the PHP answer, value for value.
        ->and($narrowed)->toEqualCanonicalizing($placed);
});

test('a narrowing by district narrows on the district and on nothing else', function (): void {
    // Subscribed-only is BlastAudience's and does not travel: the supporter list
    // may legitimately show somebody who unsubscribed.
    $subscribed = Supporter::factory()->create([
        'postcode' => '02141',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
    $unsubscribed = Supporter::factory()->create([
        'postcode' => '02115',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);
    Supporter::factory()->create(['postcode' => '90232']);

    expect(DistrictNarrowing::apply(Supporter::query(), Seat::parse('MA-07', $this->relation), $this->relation)->pluck('id')->all())
        ->toEqualCanonicalizing([$subscribed->getKey(), $unsubscribed->getKey()]);
});

test('a district the relation has no ZIP code for narrows to nobody, never to everybody', function (): void {
    foreach (districtCorpus() as $postcode) {
        Supporter::factory()->create(['postcode' => $postcode]);
    }

    // California has no 99th district: what a stored seat becomes if a later
    // relation drops it. The positive half is the first test in this file.
    expect(DistrictNarrowing::apply(Supporter::query(), Seat::fromGeoid('0699'), $this->relation)->count())->toBe(0)
        ->and(Supporter::query()->count())->toBe(count(districtCorpus()));
});

test('a list of a district\'s ZIP codes handed in narrows by the district\'s rule, not the prefix matcher\'s', function (): void {
    // A list kept from an earlier reading of the relation (D-38) has to reach
    // exactly who the district did, including leaving out every value that
    // only begins with one of its ZIP codes -- which the prefix matcher would
    // reach.
    $ids = [];

    foreach (districtCorpus() as $what => $postcode) {
        $ids[$what] = Supporter::factory()->create(['postcode' => $postcode])->getKey();
    }

    $seat = Seat::parse('MA-07', $this->relation);
    $kept = DistrictClaim::claimableIn($seat, $this->relation);

    expect(DistrictNarrowing::toZipCodes(Supporter::query(), $kept)->pluck('id')->all())
        ->toEqualCanonicalizing([
            $ids['MA-07, five digits'],
            $ids['MA-07, ZIP+4 with a hyphen'],
            $ids['MA-07, ZIP+4 with a space'],
            $ids['MA-07, ZIP+4 run together'],
            $ids['MA-07, with spaces around it'],
            $ids['MA-07, another ZIP code wholly inside it'],
        ])
        ->toEqualCanonicalizing(DistrictNarrowing::apply(Supporter::query(), $seat, $this->relation)->pluck('id')->all());
});

test('a list holding anything but five-digit ZIP codes narrows to nobody, never to more', function (array $zipCodes): void {
    // The list is bound as one array literal joined with commas, so an element
    // holding a comma would be read as two ZIP codes. `02115` is reached only if
    // that happens, and the first case is exactly that. Without the check, the
    // comma, the line break and the bad element among good ones reach
    // somebody, and the brace and the number raise instead; the empty list is
    // kept at nobody by the statement itself.
    $inTheList = Supporter::factory()->create(['postcode' => '02141']);
    Supporter::factory()->create(['postcode' => '02115']);

    // The positive half through the same call in the same run, so this is not a
    // statement that reaches nobody for everything.
    expect(DistrictNarrowing::toZipCodes(Supporter::query(), $zipCodes)->pluck('id')->all())->toBe([])
        ->and(DistrictNarrowing::toZipCodes(Supporter::query(), ['02141'])->pluck('id')->all())->toBe([$inTheList->getKey()]);
})->with([
    'an element holding a comma' => [['02141,02115']],
    'an element holding a brace' => [['02141}', '02115']],
    'a ZIP code with a line break after it' => [["02141\n"]],
    'a number rather than a string' => [[2141]],
    'one bad element among good ones' => [['02141', '0211']],
    'no ZIP codes at all' => [[]],
]);
