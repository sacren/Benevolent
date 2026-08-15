<?php

declare(strict_types=1);

namespace App\Districts;

use App\Models\Supporter;
use App\Supporters\PostcodeNarrowing;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrowing a query over supporters to the people the product may say are in
 * one congressional district.
 *
 * **This is DistrictClaim's question asked of the database, and deliberately
 * not PostcodeNarrowing's (D-37).** Two readers already answered "is this
 * supporter in this place", and they disagree about a stored `02141abc`: the
 * prefix matcher reaches it through `02141`, because it compares leading
 * characters, while DistrictClaim calls it not a ZIP and claims nothing for it.
 * A narrowing by district is a claim that each supporter it reaches is in the
 * district, so it has to be the reader that refuses to claim from a value that
 * is not a ZIP. That also makes it agree with the supporter list's District
 * column row for row: a supporter this narrowing reaches is one the list shows
 * placed in the same seat, and one it leaves out is one the list does not.
 *
 * What the other reader's disagreement costs is stated rather than smoothed. A
 * segment of ZIP code prefixes still reaches `02141abc` through `02141`,
 * because a prefix is a postal question that claims no district (D-24, D-33),
 * and D-42 decided a stored value is kept as given rather than refused. So a
 * campaign with such a value on its list would find the person under a
 * segment on `02141` and not under one on MA-07. The two answer different
 * questions, and only the second promises anything about a district.
 *
 * **Composed as a set of ZIP codes passed to SQL, never as a claim worked out
 * for each supporter in PHP.** The claimable ZIP codes of one district --
 * between 1 and 494 of them in the relation this release ships -- are read
 * from the relation once, about 14 ms, and compared in one statement, so the
 * cost does not grow with anything PHP does per supporter. Measured at Phase 4
 * Step 5 through this class against 250,000 supporters, a count costs 49.3 ms
 * for MA-07's 17 ZIP codes and 50.1 ms for WV-01's 494 (medians of five),
 * where one ZIP code prefix costs 44 ms and three cost 96 ms.
 *
 * **Why the ZIP codes are compared before the shape is checked, and why that
 * needs a `case` to say so.** Written as two conditions joined by `and`, the
 * planner evaluated the pattern first, on every row, and the same count took
 * 114.5-115.5 ms. The pattern only has to be asked of a value whose first five
 * characters are already one of the district's ZIP codes, and `case` is the
 * form in which SQL promises an order of evaluation.
 *
 * **The ZIP codes can also be handed in rather than worked out (D-38).** A list
 * kept from an earlier reading of the relation cannot be worked out again once
 * a release has replaced that relation, because only one Congress's relation
 * ships at a time -- so toZipCodes() is the same statement over a list the
 * caller already has, and such a list is answered by the district's rule rather
 * than by PostcodeNarrowing's.
 *
 * **Like PostcodeNarrowing, it narrows and does nothing else.** Subscribed-only
 * is BlastAudience's invariant and does not travel here, because the supporter
 * list may legitimately show somebody who unsubscribed.
 */
final class DistrictNarrowing
{
    /**
     * What each element of a list of ZIP codes must be before it is bound:
     * five digits and nothing else.
     */
    private const string ZIP_CODE = '/^[0-9]{5}$/D';

    /**
     * Narrow a supporter query to the supporters the product may say are in
     * the seat, as the relation's Congress drew it.
     *
     * A seat the relation has no ZIP code for -- one it does not name --
     * narrows to nobody, because an empty set matches no row, and never falls
     * through to the whole list.
     *
     * @param  Builder<Supporter>  $query
     * @return Builder<Supporter>
     */
    public static function apply(Builder $query, Seat $seat, ZctaDistricts $relation): Builder
    {
        return self::toZipCodes($query, DistrictClaim::claimableIn($seat, $relation));
    }

    /**
     * Narrow a supporter query to the supporters whose ZIP code is one of these,
     * by the rule apply() narrows to a district with.
     *
     * For a list of a district's claimable ZIP codes kept from an earlier
     * reading of the relation rather than worked out now (D-38), so that it is
     * replayed by the district's own rule. PostcodeNarrowing would reach a
     * stored `02141abc` through `02141`, which this statement does not, and a
     * narrowing to a district would then reach somebody the district column
     * calls not a ZIP.
     *
     * **Any element that is not five digits narrows the whole list to nobody.**
     * The list is bound as one array literal, joined with commas, so an element
     * holding a comma is read by PostgreSQL as more than one ZIP code: measured,
     * `["02141,90210", "02142"]` binds as `{02141,90210,02142}`, three elements,
     * and `90210` matches. A list read from the relation cannot hold one; a list
     * read back from a column is only as good as whatever last wrote the column,
     * so it is checked here rather than trusted. Refusing the whole list rather
     * than dropping the element is the fail-closed reading: a kept list that
     * cannot be read as written is not one to narrow by. An empty list narrows to
     * nobody too, because an empty array matches no row.
     *
     * @param  Builder<Supporter>  $query
     * @param  array<array-key, mixed>  $zipCodes
     * @return Builder<Supporter>
     */
    public static function toZipCodes(Builder $query, array $zipCodes): Builder
    {
        $checked = [];

        foreach ($zipCodes as $zipCode) {
            if (! is_string($zipCode) || preg_match(self::ZIP_CODE, $zipCode) !== 1) {
                return $query->whereRaw('false');
            }

            $checked[] = $zipCode;
        }

        $folded = PostcodeNarrowing::FOLDED_POSTCODE;

        // Bound as one array literal rather than one placeholder per ZIP code,
        // which the check above is what makes safe.
        //
        // **No `::text[]` on the placeholder, and the cast is the whole
        // difference between 50 ms and 10 s.** Written `any(?::text[])`, the
        // same count took 408 ms for MA-07 and 10.7 s for WV-01 against
        // 250,000 supporters: the value arrives as text and is cast by
        // `array_in`, which is not immutable, so PostgreSQL does not fold the
        // cast when planning and parses the whole list again on every row.
        // Left untyped, the server infers `text[]` from `= any(...)` and reads
        // the value once.
        return $query->whereRaw(
            "case when left({$folded}, 5) = any(?) then {$folded} ~ ? else false end",
            ['{'.implode(',', $checked).'}', DistrictClaim::SHAPE],
        );
    }
}
