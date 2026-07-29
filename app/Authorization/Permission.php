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
     * module arrived; ExportSupporters and DeleteSupporters below are the
     * second and third, and SendBlasts the fourth. (A count in prose is a claim
     * nothing runs, so it rots — this one is kept current in the same edit that
     * moves it, which is the only discipline that works.)
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
     * Take the campaign's whole list out of the campaign, as one file.
     *
     * Owner-only, and the second supporter ability the two roles disagree
     * about. **The weaker claim is the true one, and it is worth writing down
     * before somebody reads more into this than it says:** Staff already see
     * every supporter on the list page — name, address, postcode, status, the
     * whole table, unpaginated — so this does not keep the list confidential
     * from them and could not. What it withholds is the *single action* that
     * turns the list into a portable artifact with a filename, ready to hand to
     * anybody. Copying the page by hand produces something ragged and takes as
     * long as the list is long; a download does not.
     *
     * That is the same shape as DeleteSupporters below, which is withheld even
     * though Staff may overwrite every column, and it is settled the same way:
     * regret asymmetry. Withholding this and granting it later costs a campaign
     * nothing; granting it now and revoking it later is a change its staff
     * would feel.
     *
     * Trigger to revisit: the first campaign where an Owner is the bottleneck
     * for routine list work — reporting to a coalition partner, say — or a
     * third role for whom taking the list out *is* the job.
     */
    case ExportSupporters = 'export-supporters';

    /**
     * Remove a supporter from the campaign permanently.
     *
     * The other supporter ability the two roles disagree about, and the reason
     * is leverage rather than reachability. A supporter row carries no soft delete
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
     * for routine list cleanup, or an import producing rows that need removing
     * in bulk.
     */
    case DeleteSupporters = 'delete-supporters';

    /**
     * See the blasts a campaign has written, and what became of them.
     *
     * Both roles hold this, for the reason both hold ViewSupporters: a
     * campaign's staff doing the campaign's work needs to know what the
     * campaign has already said to its list, if only to avoid saying it twice.
     * Withholding it would also make SendBlasts below unreadable — an operator
     * who may not see a blast cannot be shown why they may not send one.
     *
     * Trigger for it to start refusing someone: the one ViewSupporters already
     * records, a role that may read the campaign's work without doing it.
     */
    case ViewBlasts = 'view-blasts';

    /**
     * Write a blast and choose who it is aimed at, without sending it.
     *
     * Both roles hold this, and it is EditSupporters' shape granted for
     * EditSupporters' reason: composing is the campaign's work rather than
     * authority over it. A draft is a document, and a draft that is never sent
     * has reached nobody. Writing and re-aiming share one permission because an
     * operator who may write a message but not correct the postcodes it is
     * aimed at is not a role anybody would design on purpose.
     *
     * **What keeps this safe to grant is that it stops at the draft.** The
     * irreversible half is SendBlasts below, and the two are separate cases
     * precisely so that this one can be given away freely.
     */
    case EditBlasts = 'edit-blasts';

    /**
     * Commit a blast to sending, and with it the campaign's name.
     *
     * Owner-only, and the strongest case the Owner/Staff split has yet had.
     * ExportSupporters and DeleteSupporters are both withheld on leverage
     * rather than on reachability, and both are recoverable in the way that
     * matters: a deleted supporter can be imported again, and an exported file
     * is a copy of what its holder could already read on screen. **A sent
     * message is neither.** It leaves the platform, it arrives with people who
     * are not part of the campaign, and no code path here or anywhere else
     * brings it back.
     *
     * The same regret asymmetry settles it, and more sharply than anywhere
     * else it has been applied: withholding this and granting it later costs a
     * campaign one conversation, while granting it now and revoking it later is
     * a change made after the message somebody regrets has already gone.
     *
     * Said at its true strength, because the weaker claim is the true one. This
     * decides *who may start a send* and nothing else. It does not make
     * double-sending impossible — the check constraint on `blasts` and the lock
     * the sending path will carry are what do that — and it does not stop a
     * Staff operator writing anything they like, since EditBlasts above is
     * deliberately theirs.
     *
     * Trigger to revisit: the first campaign where an Owner is the bottleneck
     * for routine sending — a weekly bulletin somebody other than the director
     * actually writes and posts. That is the export trigger's shape and it is
     * likelier to arrive, because sending is a rhythm rather than an errand.
     */
    case SendBlasts = 'send-blasts';
}
