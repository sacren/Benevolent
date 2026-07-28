<?php

declare(strict_types=1);

namespace App\Blasts;

/**
 * Where one message a campaign has written has got to.
 *
 * The states exist so that an operator can be told something true at every
 * moment about a message that may already be in other people's inboxes. That is
 * a sharper obligation than the import's: an import that reports nothing has
 * lost the operator some time, while a blast that reports nothing has left a
 * campaign believing it has contacted its supporters when it has not.
 *
 * **Draft is the only state a blast can be edited or sent from, and it is the
 * only one it can never return to.** Everything below Draft is downstream of a
 * decision the campaign cannot take back, and the database says so rather than
 * this file: `blasts` carries a check constraint tying Draft to the absence of
 * a `queued_at`, so no code path can produce a row that claims both.
 */
enum BlastStatus: string
{
    /**
     * Written, not sent, and still the campaign's to change.
     *
     * The state every blast arrives in, and the only one from which the
     * audience rule and the message still mean "what we intend to send" rather
     * than "what we sent".
     */
    case Draft = 'draft';

    /**
     * The campaign has committed the blast to sending and the work is queued.
     *
     * Separate from Sending for the reason ImportStatus separates Pending from
     * Running, and the stakes are higher here. A job can sit in the queue for a
     * long time behind other work -- or forever, where no worker is running at
     * all -- and "we have not started yet" is a different and far more
     * actionable answer than "we started and have not finished". Collapsing the
     * two would make an unclaimed blast indistinguishable from one in flight,
     * which is exactly the state a campaign must be able to see.
     */
    case Queued = 'queued';

    /**
     * A worker has picked the blast up and messages are going out.
     */
    case Sending = 'sending';

    /**
     * Every supporter the rule selected has been attempted.
     *
     * Not the same as "everyone received it": a message can be accepted for
     * delivery and still never arrive, and nothing here knows that. What this
     * says is that the send ran to the end of its audience.
     */
    case Sent = 'sent';

    /**
     * The send stopped before the end of its audience, and the record says so.
     *
     * Whatever went out stays gone. There is no unwinding a blast the way an
     * import can be re-run over the same file -- which is why a failed blast is
     * a state of its own rather than a return to Draft, and why resuming one is
     * a question about who was already reached rather than about this column.
     */
    case Failed = 'failed';

    /**
     * The status a blast has when nothing says otherwise.
     *
     * Named here rather than left as a literal at each call site so that the
     * database default and the application cannot drift apart. The migration
     * still hardcodes its own copy -- it has to stay frozen -- and a test pins
     * the two together.
     */
    public static function default(): self
    {
        return self::Draft;
    }

    /**
     * Whether this blast has reached a state it will not leave on its own.
     *
     * The question a surface asks to decide whether to keep polling, answered
     * here rather than by each surface listing the terminal cases -- a surface
     * that listed them would be one new case away from polling forever.
     */
    public function isFinished(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }

    /**
     * Whether the campaign has committed this blast to sending.
     *
     * The question that decides whether a blast may still be edited, re-aimed
     * or sent, and it is deliberately the negative of Draft rather than a list
     * of the other four: a fifth case added later is committed unless somebody
     * remembers to say so, and that default is the safe direction. The database
     * holds the same claim as a check constraint against `queued_at`, so this
     * is the reading of a fact rather than the definition of one.
     */
    public function isCommitted(): bool
    {
        return $this !== self::Draft;
    }
}
