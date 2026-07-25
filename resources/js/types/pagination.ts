/**
 * One page of a list, as Laravel's length-aware paginator serializes it.
 *
 * Written from the shape the server actually produced rather than from the
 * framework's documentation: a paginator was rendered and every key read back,
 * which is where `page` on a link came from — it is a recent addition and is
 * easy to miss when copying a type from an older project.
 *
 * **`total` is the field that matters most to a reader of this type.** It is
 * the size of the whole list, while `data.length` is the size of this page, and
 * confusing the two is the defect a paginated page invites: a heading counting
 * `data` reports the page size back to the operator as if it were the number of
 * people the campaign has.
 */
export type Paginated<T> = {
    data: T[];
    /** The whole list, not this page. */
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    /** Null on an empty list, where there is no first or last row to number. */
    from: number | null;
    to: number | null;
    path: string;
    first_page_url: string;
    last_page_url: string;
    next_page_url: string | null;
    prev_page_url: string | null;
    links: PaginationLink[];
};

/**
 * One control in the paginator's own rendered strip.
 *
 * `url` is null for a control that cannot be followed — the "Previous" arrow on
 * the first page, and the ellipsis standing in for a run of skipped pages — so
 * it is what distinguishes a link from a label rather than a field to assume
 * present.
 */
export type PaginationLink = {
    url: string | null;
    label: string;
    page: number | null;
    active: boolean;
};
