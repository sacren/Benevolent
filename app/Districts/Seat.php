<?php

declare(strict_types=1);

namespace App\Districts;

use InvalidArgumentException;

/**
 * One seat in the House of Representatives, named the way people name it:
 * `CA-37`, `TX-07`, `AK-AL`.
 *
 * **A seat is its name, not its boundaries.** The Census writes a district as a
 * GEOID -- a two-digit state code and a two-digit district, `0637` -- and those
 * four digits stay the same from one Congress to the next while the ground they
 * cover moves. So a seat carries no Congress. Which boundaries a claim about it
 * was made against is the business of whoever made the claim (D-43), and
 * ZctaDistricts is where that is recorded.
 *
 * **At-large and delegate seats are both `AL`.** The relation codes the six
 * states with one seat as district `00` and the delegates and the resident
 * commissioner as `98`. Each of those twelve is the only seat its state or
 * territory has, so the name people use for all twelve is the same one.
 *
 * **The state table is transcribed from the Census Bureau's own
 * `geo/docs/reference/state.txt`** (1,485 bytes, served with `Last-Modified:
 * Wed, 13 Mar 2013`), by script rather than by hand, and compared back against
 * it. It holds exactly the 56 state and territory codes the shipped relation
 * uses; the file's fifty-seventh row, `74` for the U.S. Minor Outlying Islands,
 * has no seat and so no place here. A code in the relation that this table does
 * not know is refused rather than printed as digits, because a seat shown as
 * `98-01` is a seat nobody can recognise.
 */
final class Seat
{
    /**
     * Postal abbreviations by Census state code.
     *
     * Keyed by array-key rather than string because PHP turns a numeric string
     * key such as "48" into an integer while leaving "06" alone -- the same thing
     * ZctaDistricts records about ZCTAs. A lookup by the two-digit string finds
     * either kind, since PHP converts the key the same way on the way in.
     *
     * @var array<array-key, string>
     */
    private const array STATES = [
        '01' => 'AL',
        '02' => 'AK',
        '04' => 'AZ',
        '05' => 'AR',
        '06' => 'CA',
        '08' => 'CO',
        '09' => 'CT',
        '10' => 'DE',
        '11' => 'DC',
        '12' => 'FL',
        '13' => 'GA',
        '15' => 'HI',
        '16' => 'ID',
        '17' => 'IL',
        '18' => 'IN',
        '19' => 'IA',
        '20' => 'KS',
        '21' => 'KY',
        '22' => 'LA',
        '23' => 'ME',
        '24' => 'MD',
        '25' => 'MA',
        '26' => 'MI',
        '27' => 'MN',
        '28' => 'MS',
        '29' => 'MO',
        '30' => 'MT',
        '31' => 'NE',
        '32' => 'NV',
        '33' => 'NH',
        '34' => 'NJ',
        '35' => 'NM',
        '36' => 'NY',
        '37' => 'NC',
        '38' => 'ND',
        '39' => 'OH',
        '40' => 'OK',
        '41' => 'OR',
        '42' => 'PA',
        '44' => 'RI',
        '45' => 'SC',
        '46' => 'SD',
        '47' => 'TN',
        '48' => 'TX',
        '49' => 'UT',
        '50' => 'VT',
        '51' => 'VA',
        '53' => 'WA',
        '54' => 'WV',
        '55' => 'WI',
        '56' => 'WY',
        '60' => 'AS',
        '66' => 'GU',
        '69' => 'MP',
        '72' => 'PR',
        '78' => 'VI',
    ];

    private function __construct(public readonly string $geoid) {}

    /**
     * The seat a Census district GEOID names.
     *
     * Refuses `ZZ`, which the Census uses for areas -- open water, in the
     * relation this release ships -- that belong to no district, because there
     * is no seat there to name.
     */
    public static function fromGeoid(string $geoid): self
    {
        if (preg_match('/^(\d{2})\d{2}$/', $geoid, $match) !== 1) {
            throw new InvalidArgumentException("\"{$geoid}\" is not the GEOID of a congressional district.");
        }

        if (! array_key_exists($match[1], self::STATES)) {
            throw new InvalidArgumentException("\"{$geoid}\" names a state code no seat belongs to.");
        }

        return new self($geoid);
    }

    /**
     * The seat somebody typed, if the relation names it.
     *
     * Accepts the forms people write -- `CA-37`, `ca-7`, `CA 37`, `AK-AL`,
     * `DC-AL` -- and answers null for anything else, including a well-formed
     * seat that does not exist: `CA-53` is refused because California has 52,
     * and `AK-01` because Alaska's only seat is at-large. It is checked against
     * the relation rather than against a count of seats per state, because the
     * relation is where every answer about the seat will be read from, and a
     * seat it does not name could never be found in it.
     */
    public static function parse(string $typed, ZctaDistricts $relation): ?self
    {
        if (preg_match('/^([A-Z]{2})-?(AL|\d{1,2})$/', strtoupper(str_replace(' ', '', $typed)), $match) !== 1) {
            return null;
        }

        $code = array_search($match[1], self::STATES, true);

        if ($code === false) {
            return null;
        }

        $state = str_pad((string) $code, 2, '0', STR_PAD_LEFT);

        $candidates = $match[2] === 'AL'
            ? [$state.'00', $state.'98']
            : [$state.str_pad($match[2], 2, '0', STR_PAD_LEFT)];

        $named = array_values(array_intersect($candidates, $relation->districts()));

        return $named === [] ? null : new self($named[0]);
    }

    /**
     * The seat as people write it: the state's postal abbreviation, then the
     * district number as two digits, or `AL` for a state's only seat.
     */
    public function label(): string
    {
        $state = self::STATES[substr($this->geoid, 0, 2)];
        $district = substr($this->geoid, 2);

        return $state.'-'.(in_array($district, ['00', '98'], true) ? 'AL' : $district);
    }
}
