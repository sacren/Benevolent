/**
 * A message a campaign has written to the people on its list, as the server
 * sends one.
 *
 * `postcode_prefixes` is the whole of the stored audience rule, and null means
 * every supporter the campaign may contact. There is deliberately no field here
 * for the subscribed-only half: that condition is enforced in the query rather
 * than chosen by an operator, so it is not something the server sends and not
 * something a page could offer to turn off.
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
    postcode_prefixes: string[] | null;
    status: BlastStatus;
    queued_at: string | null;
    finished_at: string | null;

    /** Why the send stopped, for a blast that reached `failed`. */
    failure_reason: string | null;
    created_at: string;
    updated_at: string;
};

/**
 * Mirrors App\Blasts\BlastStatus. Draft is the only state a blast can be edited
 * from and the only one it can never return to.
 */
export type BlastStatus = 'draft' | 'queued' | 'sending' | 'sent' | 'failed';
