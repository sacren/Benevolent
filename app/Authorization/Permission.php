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
     * The one thing that distinguishes Owner from Staff today, and the reason
     * the distinction exists at all — a campaign needs someone who can decide
     * its roster without every operator being able to.
     */
    case ManageOperators = 'manage-operators';
}
