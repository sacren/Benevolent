<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Something worth remembering happened inside a campaign.
 *
 * The list is deliberately short, and short in a particular direction: it holds
 * changes to *who may act in this campaign and with what authority*, not every
 * change an operator can make. That scope is a decision rather than a starting
 * point.
 *
 * A trail that records everything is the failure mode this enum exists to
 * avoid. It reads as thorough, costs nothing to write, and is worthless to
 * read — the one entry that matters sits among a thousand password changes and
 * profile renames, none of which anyone will ever ask a question about. Worse,
 * a test asserting "the change was recorded" passes just as happily against
 * such a recorder as against a considered one, so the indiscriminate version
 * cannot be told from this one by its tests. Every case below therefore has to
 * earn its place by naming a question someone would actually ask of the trail.
 *
 * What is left out today, and deliberately: password changes, two-factor
 * enrolment, passkey management, profile and email edits. Those are an
 * operator's own account rather than the campaign's roster, and Fortify already
 * notifies the operator themselves when they happen.
 */
enum AuditEvent: string
{
    /**
     * An operator came into existence inside this campaign.
     *
     * The interesting half is the authority they arrived with. Registration is
     * open on every campaign, and the first operator to reach a fresh one
     * claims it as Owner — including someone who simply guessed the hostname.
     * Nothing prevents that today, which is precisely why it is worth being
     * able to see afterwards that it happened, and when.
     */
    case OperatorRegistered = 'operator-registered';

    /**
     * An operator's authority within this campaign changed.
     *
     * The literal form of "who granted whom what". No surface in the
     * application produces one yet — the permission that would govern it has no
     * consumer — so this records a change made by any means, and is already in
     * place for the first screen that offers one.
     */
    case OperatorRoleChanged = 'operator-role-changed';

    /**
     * An operator ceased to exist inside this campaign.
     *
     * Only self-removal is reachable today, through the profile settings page.
     * The entry has to carry enough of the operator to stay readable, because
     * by the time anyone looks, the row it describes is gone.
     */
    case OperatorRemoved = 'operator-removed';
}
