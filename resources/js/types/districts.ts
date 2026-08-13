/**
 * Mirrors App\Districts\DistrictAnswer: the five things the product can say
 * about a supporter's district. Only `placed` names one; the other four are
 * different reasons it cannot, kept apart because each asks something different
 * of an operator.
 */
export type DistrictAnswer =
    'placed' | 'split' | 'unmapped' | 'malformed' | 'missing';

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
    bySupporter: Record<number, DistrictClaim>;
};
