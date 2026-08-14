/**
 * A narrowing of a campaign's supporter list that the campaign has named, as
 * the server sends one.
 *
 * The stored rule is `postcode_prefixes` or `district`, exactly one of them
 * (D-37): the database refuses a segment naming both or neither. So the two
 * `| null`s are one guarantee rather than two optional fields — whichever is
 * null, the other is not. A null `postcode_prefixes` means the segment narrows
 * by district; it never means what a null does on a blast, "everyone this
 * campaign may contact", which is a widening no segment can reach.
 *
 * `district` is a seat's name as people write it, `MA-07`, and names the
 * seat as the relation the server reads draws it (D-43).
 *
 * There is deliberately no field for subscription status. A segment says
 * *where*; whether somebody may be contacted at all is the sending path's
 * invariant, and on the supporter list it is a separate matter that is no part
 * of any stored rule (D-24).
 */
export type Segment = {
    id: number;

    /** Who named it. Null once that operator has left the campaign. */
    operator_id: number | null;
    name: string;
    postcode_prefixes: string[] | null;
    district: string | null;
    created_at: string;
    updated_at: string;
};

/**
 * What the segment list needs to name a district segment's map, sent only when
 * the campaign has named one: the Congress, formatted for reading ("119th"),
 * and the district segments whose seat that map does not name.
 */
export type SegmentDistricts = {
    congress: string;
    unnamed: number[];
};
