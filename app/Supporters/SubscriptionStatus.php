<?php

declare(strict_types=1);

namespace App\Supporters;

/**
 * Whether a campaign may still contact one of its supporters.
 *
 * A list's reach decays silently — a "12,000-person list" carrying 4,000
 * addresses nobody may write to is an 8,000-person list that has never been
 * measured — so the status is the column that keeps the campaign's own numbers
 * honest rather than a preference stored for tidiness.
 *
 * An enum rather than a boolean, for two reasons. This application's vocabulary
 * is already enums whose docblocks carry the meaning (OperatorRole, Permission,
 * AuditEvent), and a string-backed enum gains its next case for free where a
 * boolean would need a migration and a data transform to grow one.
 *
 * The case that is deliberately absent is `Bounced`, which is what "reach decays
 * silently" actually points at. Nothing can write it until this platform sends
 * mail to supporters, and a state nothing writes is a guess at a future rather
 * than a description of the data — the shape L-20 names. Choosing the
 * representation that can hold it later is not the same as building it now.
 */
enum SubscriptionStatus: string
{
    /**
     * The campaign may contact this supporter.
     *
     * The state everyone arrives in, and the reason that is worth recording is
     * that **importing a list is not consent**. Defaulting to subscribed takes
     * the operator's assertion that they hold an opt-in, which this application
     * cannot verify and is not the system of record for; defaulting to
     * unsubscribed would make every import useless. The trigger to revisit is
     * the first campaign under a regime demanding *proof* of opt-in, at which
     * point consent provenance — source, timestamp, address — is owed, and that
     * is acquisition-source-shaped work this MVP's goal excludes.
     */
    case Subscribed = 'subscribed';

    /**
     * The supporter has asked not to be contacted.
     *
     * Kept as a supporter rather than deleted, because the record of the request
     * is what stops a later import putting them straight back on the list.
     */
    case Unsubscribed = 'unsubscribed';

    /**
     * The status a supporter has when nothing says otherwise.
     *
     * Named here rather than left as a literal at each call site so that the
     * database default, the factory and any future importer cannot drift apart.
     * The migration still hardcodes its own copy — it has to stay frozen — and a
     * test pins the two together.
     */
    public static function default(): self
    {
        return self::Subscribed;
    }
}
