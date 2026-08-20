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

    /**
     * What the aim named at the moment the campaign committed the blast, for a
     * blast that pointed at a segment of ZIP code prefixes (D-27). Null for a
     * draft, for a blast carrying its own rule, for one aimed at everybody, and
     * for one aimed at a district — the database ties a frozen rule to exactly
     * the rows that have one, and this is the column holding it for a segment
     * that narrows by prefix.
     *
     * **This, and not the segment, is what a committed blast actually reached.**
     * A segment stays editable after a blast has gone out, so `segment` is a
     * live value that may have moved since; a page describing a sent blast from
     * it would be reporting today's narrowing as though it were the one the
     * campaign used.
     */
    committed_prefixes: string[] | null;

    /**
     * The other frozen rule: the ZIP codes a district-aimed blast was committed
     * to, as the relation shipping at that moment claimed them (D-38). Null for
     * a draft, for a blast carrying its own rule, for one aimed at everybody,
     * and for a committed blast that pointed at a segment of prefixes — exactly
     * one of the two frozen columns is set on a committed segment-aimed blast,
     * and neither on anything else.
     *
     * A seat's name is deliberately not what is frozen: the set of ZIP codes it
     * claims is a fact about data that ships with a release, so the name alone
     * would describe a different audience after the next one.
     */
    committed_zip_codes: string[] | null;
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

    /**
     * How many of this blast's copies went out carrying a link that could name
     * the message it came from (D-48).
     *
     * **This is the basis `withdrawn_count` is drawn from, and it is why a zero
     * beside it can be read honestly.** Copies claimed before messages carried
     * their own link hold no token, so a withdrawal through one of them could
     * never have been credited to this blast -- and a page showing such a blast
     * `0` would be presenting an absence of evidence as evidence of absence.
     * Zero here means *not recorded*; equal to `reached_count` means the count
     * beside it is the whole answer; anything between means it is a floor.
     *
     * A copy whose message never went is not counted, even though it holds a
     * token: the claim mints one before the mailer is called, so a refused copy
     * carries a link that reached nobody.
     */
    attributable_count: number;

    /**
     * How many people used one of this blast's own links to leave (D-48).
     *
     * Acts of leaving rather than people, and rather than copies: one copy can
     * be followed by more than one withdrawal, because somebody can leave, be
     * put back by an operator, and leave again. Never a share of the campaign's
     * unattributed withdrawals -- those name no message and belong to no blast.
     */
    withdrawn_count: number;
    created_at: string;
    updated_at: string;
};

/**
 * Mirrors App\Blasts\BlastStatus. Draft is the only state a blast can be edited
 * from and the only one it can never return to.
 */
export type BlastStatus = 'draft' | 'queued' | 'sending' | 'sent' | 'failed';
