/**
 * A narrowing of a campaign's supporter list that the campaign has named, as
 * the server sends one.
 *
 * `postcode_prefixes` is the whole of the stored rule, and unlike a blast's
 * column of the same name it is never null: a segment that narrows nothing is
 * not a segment, and null on a blast means "everyone this campaign may
 * contact" — a widening that must not be reachable through a segment. So there
 * is no `| null` here, and that absence is the type carrying a schema
 * guarantee rather than an omission.
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
    postcode_prefixes: string[];
    created_at: string;
    updated_at: string;
};
