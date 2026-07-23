/**
 * A supporter as the server sends one.
 *
 * Every name field is nullable and that is the schema being honest rather than
 * loose: `name` is what the source called this person, while `given_name` and
 * `family_name` are provenance — recorded only when the source actually split
 * them, never inferred from a single string. A row with an address and no name
 * at all is valid, because a petition widget that asked only for an email
 * produces exactly that and the person is still contactable.
 */
export type Supporter = {
    id: number;
    name: string | null;
    given_name: string | null;
    family_name: string | null;
    email: string;
    postcode: string | null;
    subscription_status: SubscriptionStatus;
    created_at: string;
    updated_at: string;
};

/**
 * Mirrors App\Supporters\SubscriptionStatus. Two cases, deliberately: a third,
 * `Bounced`, waits for a sending path that could write it.
 */
export type SubscriptionStatus = 'subscribed' | 'unsubscribed';

/**
 * One attempt at getting an existing list into the campaign, as the server
 * sends it.
 *
 * `mapping` is null until the operator has said what their file's columns mean,
 * and that null is the page's whole state machine: an import with no mapping is
 * waiting to be told, and one with a mapping is queued, running or done.
 */
export type SupporterImport = {
    id: number;
    operator_id: number | null;
    original_filename: string;
    stored_path: string;
    headers: string[];
    mapping: ColumnMapping | null;
    status: ImportStatus;
    rows_read: number;
    supporters_added: number;
    supporters_updated: number;
    rows_skipped: number;
    failure_reason: string | null;
    finished_at: string | null;
    created_at: string;
    updated_at: string;
};

/**
 * Mirrors App\Supporters\ImportStatus. Whether an import has finished is
 * deliberately *not* derived from this in the browser — the server sends a
 * `finished` prop instead, so a case added here cannot leave a page polling
 * forever because somebody forgot to extend a union.
 */
export type ImportStatus =
    'awaiting_mapping' | 'pending' | 'running' | 'completed' | 'failed';

/**
 * Mirrors App\Supporters\NameColumnMode. Three cases, and there is deliberately
 * no fourth that lets the importer work the name out for itself.
 */
export type NameColumnMode = 'single' | 'split' | 'none';

/**
 * Mirrors App\Supporters\ColumnMapping — the operator's statement about their
 * own file, never something inferred from its headers.
 */
export type ColumnMapping = {
    email: string;
    name_mode: NameColumnMode;
    name: string | null;
    given_name: string | null;
    family_name: string | null;
    postcode: string | null;
};
