/**
 * Mirrors App\Districts\DistrictAnswer: the five things the product can say
 * about a supporter's district. Only `placed` names one; the other four are
 * different reasons it cannot, kept apart because each asks something different
 * of an operator.
 */
export type DistrictAnswer =
    'placed' | 'split' | 'unmapped' | 'malformed' | 'missing';

/**
 * Mirrors App\Districts\SeatStanding: where a supporter stands against the
 * seat their campaign is running for. `maybe` is a ZIP code crossing the
 * seat's boundary and must never be shown as `in`.
 */
export type SeatStanding = 'in' | 'maybe' | 'not';

/**
 * What the server may say about one supporter's district, as
 * App\Districts\DistrictClaim::jsonSerialize() sends it.
 *
 * `claimed` is the only field a page may show as the supporter's district. A
 * split ZIP's districts are in `touching` and are what the supporter might be
 * in, never what they are in — so nothing here should ever read a district
 * out of `touching` and present it as the answer.
 */
export type DistrictClaim = {
    answer: DistrictAnswer;
    zip: string | null;
    touching: string[];
    claimed: string | null;
    mayHaveLostLeadingZero: boolean;
    seatStanding: SeatStanding | null;
};

/**
 * What a district segment's narrowing keeps and leaves out, when the list is
 * narrowed to one: how many ZIP codes lie wholly inside the district (the only
 * ones it reaches) and how many cross its boundary (whose supporters it does
 * not show). `seat` is null when the relation does not name the stored
 * `district`, and the narrowing then reaches nobody.
 */
export type DistrictNarrowing = {
    district: string;
    seat: string | null;
    wholly: number;
    crossing: number;
};

/**
 * The district answers for one page of the supporter list, and the map they
 * were read against.
 *
 * `congress` and `publishedOn` arrive formatted for reading ("119th",
 * "October 24, 2024"), so the page cannot shift the date across a timezone or
 * spell the ordinal differently from the server. They are what makes every
 * answer a true statement: a district is named as that Congress drew it.
 */
export type SupporterDistricts = {
    congress: string;
    publishedOn: string;
    seat: string | null;
    narrowing: DistrictNarrowing | null;
    bySupporter: Record<number, DistrictClaim>;
};
