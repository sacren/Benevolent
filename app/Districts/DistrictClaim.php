<?php

declare(strict_types=1);

namespace App\Districts;

use JsonSerializable;

/**
 * What the product may say about the congressional district of whoever holds a
 * stored ZIP code.
 *
 * **The one place D-32's rule is applied.** A district is claimed only for a
 * ZIP whose whole area lies inside one; every other supporter's district is not
 * named at all. The alternatives were measured and refused at Phase 4 Step 1:
 * claiming every district a ZIP touches tells a campaign somebody is Y's
 * constituent when they are X's, and a "dominant district" heuristic is
 * confidently wrong for the 15.2% of ZCTAs that are genuinely divided. A wrong
 * district is the failure no later change repairs, so the rule is written to be
 * wrong only by saying too little.
 *
 * **`ZZ` is ignored, and the reason is a measurement, guarded where it can be
 * checked.** The Census assigns some areas to no district and writes them as
 * `09ZZ`, `17ZZ` and so on. In the file this release ships every one of those
 * parts holds zero square metres of land -- Long Island Sound, Lake Michigan --
 * so nobody's address is in one, and a shoreline ZIP touching `1709` and `17ZZ`
 * is wholly inside `1709` for everyone who lives there. Counting `ZZ` as a
 * second district would leave 20 such ZIPs unclaimed, among them some of the
 * densest on the Chicago lakefront. The shipped relation carries no areas, so
 * the premise is checked where the areas are: `districts:build` refuses a
 * Census file in which a `ZZ` part holds land.
 *
 * **What is stored is classified, never corrected (D-42).** Phase 1 decided a
 * postcode is kept exactly as given, and that holds: nothing here writes, and a
 * value that is not a ZIP is reported as not a ZIP rather than repaired into
 * one. That matters most for the likeliest broken value -- a spreadsheet drops
 * the leading zero from `02139` and stores `2139` -- because padding it back
 * would be a guess, and a guess is exactly what this class exists not to make.
 * It is reported, and mayHaveLostLeadingZero() says why it probably happened.
 *
 * **The fold is PostcodeNarrowing's**, so `90232 1234`, `902321234` and
 * `90232-1234` all read as `90232` here exactly as they match `9023` there.
 * **The shape check is stricter than the matcher, deliberately:** a segment on
 * `90210` reaches a stored `90210abc`, because the matcher compares leading
 * characters, while this calls `90210abc` not a ZIP, because a district claimed
 * from a value that is not a ZIP is a claim nothing supports. **A narrowing by
 * district asks this class's question, not the matcher's (D-37):**
 * App\Districts\DistrictNarrowing puts SHAPE and claimableIn() into SQL, so it
 * does not reach `90210abc` either, and a district column and a narrowing to
 * the same district cannot come to disagree about who is in it.
 *
 * **These answers are relative to the relation they were read from.** A claim
 * names a seat as the relation's Congress drew it, and the relation says which
 * Congress that was (D-43).
 */
final class DistrictClaim implements JsonSerializable
{
    /**
     * What a folded value must look like to be read as a ZIP: five digits, or a
     * ZIP+4 with or without its hyphen.
     *
     * **One spelling for PHP and PostgreSQL alike**, because a narrowing by
     * district asks the database this same question and the two answers must
     * not differ. Two choices make that true. `[0-9]` rather than `\d`, since
     * PostgreSQL reads `\d` through the database's collation provider, which
     * under ICU admits other scripts' digits, while `[0-9]` means the same ten
     * characters to both. And PHP reads it with the `D` modifier, since
     * without it PCRE lets `$` match before a final line break -- `"02141\n"`
     * was read as a ZIP here while PostgreSQL's `$` refused it. The fold
     * removes spaces only, so a stored line break does reach the check: no form
     * or import writes one, since both trim, but a seeder or a hand-written row
     * can.
     */
    public const string SHAPE = '^[0-9]{5}(-?[0-9]{4})?$';

    /**
     * @param  string|null  $zip  The five-digit ZIP read from the stored value,
     *                            when it held one.
     * @param  list<Seat>  $touching  Every district the ZIP's area touches,
     *                                `ZZ` aside. One for a placed ZIP, two or
     *                                more for a split one, none otherwise.
     * @param  Seat|null  $seat  The seat the campaign is running for, if it
     *                           has recorded one (D-40).
     */
    private function __construct(
        public readonly DistrictAnswer $answer,
        public readonly ?string $zip,
        public readonly array $touching,
        private readonly bool $fourDigits,
        private readonly ?Seat $seat,
    ) {}

    /**
     * What may be said about the district of whoever holds this stored value,
     * and -- when the campaign has recorded one -- about where they stand
     * against its seat.
     */
    public static function for(?string $postcode, ZctaDistricts $relation, ?Seat $seat = null): self
    {
        $folded = str_replace(' ', '', $postcode ?? '');

        if ($folded === '') {
            return new self(DistrictAnswer::Missing, null, [], false, $seat);
        }

        // `D` makes `$` mean the end of the value; see SHAPE for why.
        if (preg_match('/'.self::SHAPE.'/D', $folded) !== 1) {
            return new self(DistrictAnswer::Malformed, null, [], preg_match('/^[0-9]{4}$/D', $folded) === 1, $seat);
        }

        $zip = substr($folded, 0, 5);

        $touching = array_map(Seat::fromGeoid(...), self::districts($relation->districtsTouching($zip)));

        return new self(match (count($touching)) {
            // Also the answer for a ZCTA touching nothing but `ZZ`, of which the
            // shipped relation has none: there would be no district to claim.
            0 => DistrictAnswer::Unmapped,
            1 => DistrictAnswer::Placed,
            default => DistrictAnswer::Split,
        }, $zip, $touching, false, $seat);
    }

    /**
     * Every ZIP code this class would place in the given seat, in the order
     * the relation holds them.
     *
     * **D-32 asked about one district rather than about one ZIP code, and it
     * has to be the same rule rather than a second one.** A ZIP code is in the
     * list exactly when for() would answer Placed and claim this seat for it:
     * its area touches this district and no other, `ZZ` aside. A ZIP code
     * crossing the boundary is left out, so a supporter in one is never
     * reached as the district's constituent -- which is exit criterion 3, one
     * district at a time. Both read the relation through districts() below, so
     * the `ZZ` rule is written once.
     *
     * **What leaving them out costs, measured at Phase 4 Step 5 by people
     * rather than by land.** Weighting each 2020 Census block by its
     * population, a narrowing to the median district reaches 69.1% of the
     * people who live in it (p10 34.8%, p90 91.1%, over 437 districts -- the
     * four island areas' delegates were not measured), and MA-07 -- whose
     * boundary crosses 26 ZIP codes against the 17 it holds whole -- reaches
     * 50.5%. Nobody in any ZIP code claimed for a district lives outside it.
     * Adding the ZIP codes that cross the boundary would reach everyone, and
     * would make the median district's audience 24.5% people who are not in
     * it, and MA-07's 37.6%.
     *
     * An empty list for a seat the relation has no area for, such as one it
     * no longer names -- and an empty list narrows to nobody.
     *
     * @return list<string>
     */
    public static function claimableIn(Seat $seat, ZctaDistricts $relation): array
    {
        return array_values(array_filter(
            $relation->zctasTouching($seat->geoid),
            static fn (string $zcta): bool => self::districts($relation->districtsTouching($zcta)) === [$seat->geoid],
        ));
    }

    /**
     * The districts in a list of GEOIDs, leaving out `ZZ` -- the areas the
     * Census assigns to no district, which hold no land (see the class
     * docblock).
     *
     * @param  list<string>  $geoids
     * @return list<string>
     */
    private static function districts(array $geoids): array
    {
        return array_values(array_filter(
            $geoids,
            static fn (string $geoid): bool => ! str_ends_with($geoid, 'ZZ'),
        ));
    }

    /**
     * The district claimed, or null whenever the product will not name one.
     *
     * The only way to read a district *as the supporter's* from this class. A
     * split ZIP's districts are in `$touching` and are what it might be, never
     * what it is.
     */
    public function claimed(): ?Seat
    {
        return $this->answer === DistrictAnswer::Placed ? $this->touching[0] : null;
    }

    /**
     * Whether a value that is not a ZIP is four digits -- which is what a
     * spreadsheet leaves of a ZIP beginning with zero, most of New England's
     * and New Jersey's among them.
     */
    public function mayHaveLostLeadingZero(): bool
    {
        return $this->fourDigits;
    }

    /**
     * Where the supporter stands against the campaign's seat, or null when
     * there is no seat to stand against or no district data to say anything
     * with.
     *
     * **In only when the ZIP code lies wholly inside the seat.** A ZIP code
     * crossing the seat's boundary is MaybeIn, never In, which is D-32's rule
     * asked about one district rather than about all of them: the product does
     * not tell a campaign somebody is its constituent on the strength of a ZIP
     * code that is partly somewhere else.
     */
    public function standing(): ?SeatStanding
    {
        if ($this->seat === null || $this->touching === []) {
            return null;
        }

        $seat = $this->seat->geoid;

        if ($this->claimed()?->geoid === $seat) {
            return SeatStanding::In;
        }

        foreach ($this->touching as $district) {
            if ($district->geoid === $seat) {
                return SeatStanding::MaybeIn;
            }
        }

        return SeatStanding::NotIn;
    }

    /**
     * The claim as a page receives it.
     *
     * **`claimed` is sent as its own field, decided here, rather than left for
     * the page to work out from `touching`.** A page that took the first of a
     * split ZIP's districts would tell a campaign the supporter is in it, which
     * is the one failure this class exists to prevent -- so the page is given
     * nothing to decide. It shows `claimed` when there is one, and otherwise
     * says why there is not.
     *
     * `seatStanding` is decided here for the same reason: a page that treated
     * "may be in your seat" as "in your seat" would be making the claim this
     * class refuses, one district at a time.
     *
     * @return array{answer: string, zip: string|null, touching: list<string>, claimed: string|null, mayHaveLostLeadingZero: bool, seatStanding: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'answer' => $this->answer->value,
            'zip' => $this->zip,
            'touching' => array_map(fn (Seat $seat): string => $seat->label(), $this->touching),
            'claimed' => $this->claimed()?->label(),
            'mayHaveLostLeadingZero' => $this->fourDigits,
            'seatStanding' => $this->standing()?->value,
        ];
    }
}
