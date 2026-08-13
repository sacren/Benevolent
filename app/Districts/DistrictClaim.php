<?php

declare(strict_types=1);

namespace App\Districts;

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
 * from a value that is not a ZIP is a claim nothing supports. Whether a segment
 * narrowing *by district* should reach such a value is D-37's to decide.
 *
 * **These answers are relative to the relation they were read from.** A claim
 * names a seat as the relation's Congress drew it, and the relation says which
 * Congress that was (D-43).
 */
final class DistrictClaim
{
    /**
     * @param  string|null  $zip  The five-digit ZIP read from the stored value,
     *                            when it held one.
     * @param  list<Seat>  $touching  Every district the ZIP's area touches,
     *                                `ZZ` aside. One for a placed ZIP, two or
     *                                more for a split one, none otherwise.
     */
    private function __construct(
        public readonly DistrictAnswer $answer,
        public readonly ?string $zip,
        public readonly array $touching,
        private readonly bool $fourDigits,
    ) {}

    /**
     * What may be said about the district of whoever holds this stored value.
     */
    public static function for(?string $postcode, ZctaDistricts $relation): self
    {
        $folded = str_replace(' ', '', $postcode ?? '');

        if ($folded === '') {
            return new self(DistrictAnswer::Missing, null, [], false);
        }

        if (preg_match('/^(\d{5})(-?\d{4})?$/', $folded, $match) !== 1) {
            return new self(DistrictAnswer::Malformed, null, [], preg_match('/^\d{4}$/', $folded) === 1);
        }

        $zip = $match[1];

        $touching = array_values(array_map(
            Seat::fromGeoid(...),
            array_filter(
                $relation->districtsTouching($zip),
                static fn (string $geoid): bool => ! str_ends_with($geoid, 'ZZ'),
            ),
        ));

        return new self(match (count($touching)) {
            // Also the answer for a ZCTA touching nothing but `ZZ`, of which the
            // shipped relation has none: there would be no district to claim.
            0 => DistrictAnswer::Unmapped,
            1 => DistrictAnswer::Placed,
            default => DistrictAnswer::Split,
        }, $zip, $touching, false);
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
}
