<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * Something an operator may be allowed to do inside their campaign.
 *
 * This is the vocabulary a module checks against — `$user->can('...')`, or the
 * `can:` middleware on a route — rather than checking a role directly. Asking
 * "may this operator manage operators?" survives the day a third role appears;
 * asking "is this operator an Owner?" has to be found and rewritten everywhere
 * it was written.
 *
 * The list is deliberately short. It holds what the foundation itself can
 * govern today, and Phase 1 modules add their own cases as they arrive with
 * something real to protect. A permission with no consumer is a guess at a
 * workflow nobody has performed.
 *
 * Every case must be registered as a gate — AuthorizationServiceProvider does
 * that by iterating this enum, so adding a case is enough, and a test asserts
 * the registration covers the whole enum. An unregistered permission would not
 * throw; it would quietly deny everyone, which is the kind of failure that
 * looks like a working guard.
 */
enum Permission: string
{
    /**
     * Govern who else may act in this campaign: inviting operators, changing
     * what they may do, and removing them.
     *
     * The reason the Owner/Staff distinction exists at all — a campaign needs
     * someone who can decide its roster without every operator being able to.
     * It was the *only* thing separating the two roles until the supporter
     * module arrived; DeleteSupporters below is now the second.
     */
    case ManageOperators = 'manage-operators';

    /**
     * See the campaign's supporter list, and any one supporter on it.
     *
     * Both roles hold this, so today it refuses nobody: a campaign's staff
     * doing the campaign's work *is* working this list, and a role that could
     * not see it could do nothing at all. It is named as a permission rather
     * than answered with a bare `true` in a policy so that OperatorRole's own
     * list stays the honest answer to "what may Staff do" -- a role whose list
     * reads empty while its holder may read every supporter in the campaign
     * would make that file lie.
     *
     * Trigger for it to start refusing someone: the first role that may read
     * the list without changing it, a phone-banking volunteer being the
     * plausible one for an advocacy campaign.
     */
    case ViewSupporters = 'view-supporters';

    /**
     * Add a supporter to the campaign's list, and change one already on it.
     *
     * Both roles hold this too, for the same reason as viewing: keeping
     * supporter details current is the campaign's work rather than authority
     * over it. Adding and changing share one permission because an operator who
     * may enter someone but not correct a typo in their address is not a role
     * anybody would design on purpose.
     */
    case EditSupporters = 'edit-supporters';

    /**
     * Remove a supporter from the campaign permanently.
     *
     * The one supporter ability the two roles disagree about, and the reason is
     * leverage rather than reachability. A supporter row carries no soft delete
     * and nothing to recover it from, and the ordinary way to stop contacting
     * somebody is SubscriptionStatus::Unsubscribed -- a status kept precisely so
     * that a later import cannot put them back on the list. Removal is
     * therefore the exceptional act, and one click per row empties a list far
     * faster than editing it row by row ever could.
     *
     * Said plainly, because the weaker claim is the true one: this protects a
     * supporter's *existence*, not their details, since Staff may overwrite
     * every column on the row. What settles it is regret asymmetry -- withholding
     * this and granting it later costs nothing, while granting it now and
     * revoking it later is a change campaigns would feel.
     *
     * Trigger to revisit: the first campaign where an Owner is the bottleneck
     * for routine list cleanup, or an import at Step 4 producing rows that need
     * removing in bulk.
     */
    case DeleteSupporters = 'delete-supporters';
}
