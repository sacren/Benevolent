import type { Segment } from './segments';

/**
 * A message a campaign has written to the people on its list, as the server
 * sends one.
 *
 * The audience rule is `segment_id` or `postcode_prefixes`, never both — the
 * database refuses a row that names two aims — and neither set means every
 * supporter the campaign may contact. **So a null `segment_id` does not mean
 * "everyone" on its own**, and a page reading only that field would say the
 * opposite of who a narrowed blast goes to.
 *
 * There is deliberately no field here for the subscribed-only half: that
 * condition is enforced in the query rather than chosen by an operator, so it
 * is not something the server sends and not something a page could offer to
 * turn off.
 */
export type Blast = {
    id: number;
    operator_id: number | null;

    /**
     * Who committed the blast to sending, which is not who wrote it:
     * `operator_id` is authorship, and Staff may draft a blast that only an
     * Owner may send.
     */
    queued_by: number | null;
    subject: string;
    body: string;

    /**
     * The narrowing this blast points at, if it points at one rather than
     * carrying its own. Null when it carries its own or aims at everybody.
     */
    segment_id: number | null;

    /**
     * That narrowing itself, eager-loaded so the list can say what a blast is
     * aimed at in the campaign's own words rather than as an id. Null exactly
     * when `segment_id` is, and present only where the server loads it — the
     * compose pages send the pointer without it.
     */
    segment?: Segment | null;
    postcode_prefixes: string[] | null;
    status: BlastStatus;
    queued_at: string | null;
    finished_at: string | null;

    /** Why the send stopped, for a blast that reached `failed`. */
    failure_reason: string | null;

    /**
     * How many supporters this blast has actually been handed to the mailer
     * for, and how many one-message failures it recorded. Counted from
     * `blast_recipients` rather than from the audience rule: the audience is a
     * prediction, and these two are what happened.
     */
    reached_count: number;
    failed_count: number;
    created_at: string;
    updated_at: string;
};

/**
 * Mirrors App\Blasts\BlastStatus. Draft is the only state a blast can be edited
 * from and the only one it can never return to.
 */
export type BlastStatus = 'draft' | 'queued' | 'sending' | 'sent' | 'failed';
