<?php

declare(strict_types=1);

namespace App\Blasts;

use App\Authorization\Permission;
use App\Models\Blast;
use App\Models\User;

/**
 * What an operator may do to the blasts of the campaign they are in.
 *
 * Every answer comes from a Permission, never from reading the operator's role,
 * for the reason SupporterPolicy gives: asking "may this operator send?"
 * survives the day a third role appears, while asking "is this operator an
 * Owner?" has to be found and rewritten everywhere somebody wrote it — and a
 * policy is the shortest place to write it, which is exactly why the temptation
 * lands here.
 *
 * **This class lives with its module rather than in app/Policies/, and that is
 * load-bearing rather than tidy (D-5).** Gate::getPolicyFor() consults an
 * explicit registration, then the #[UsePolicy] attribute, then path guessing.
 * Measured for this model rather than inherited from Supporter's measurement,
 * because a resolution order is the kind of claim that gets believed without
 * being checked: declare a class at App\Policies\BlastPolicy, delete the
 * attribute, and the gate hands back that class — found *with no attribute
 * present at all*. So a policy filed by convention would make the attribute
 * decorative, deletable with every test still green. Filed here, the attribute
 * is the only thing connecting the two, so deleting it turns the allow tests
 * red. The deny tests do not move, which is the whole point.
 *
 * **This policy answers exactly the four abilities below, and any other ability
 * checked against a Blast is denied — silently, and indistinguishably from a
 * considered refusal.** That was measured at Phase 1 Step 2: an absent ability
 * and a nonsense ability both return false and throw the same
 * AuthorizationException. A `__call` remedy was built, confirmed working and
 * declined, because it is implicit machinery in a codebase that has chosen
 * explicit wiring three times over. **What is new here is the stakes.** The
 * ability most likely to be mistyped is `send`, and its silent denial looks
 * exactly like an authorization system doing its job. So: adding any surface
 * that needs an ability not listed below means adding the method *and* its
 * allow test in the same edit. The missing method will not announce itself.
 *
 * `view` is deliberately absent, because this module ships no single-blast page
 * for it to govern — Step 3 builds a list, a create page and an edit page for a
 * draft, and nothing else. Unlike omitting an enum case, which fails loudly,
 * this omission fails silently; it is named here because that is the only place
 * it can be.
 *
 * **These methods answer authority and never state, and that is a decision
 * rather than an oversight.** A blast that has left Draft can never be sent
 * again, BlastStatus::isCommitted() says so, and it is tempting to fold that
 * into send() below. It is refused because a method returning false for two
 * different causes — *you have no authority* and *this already went* — is the
 * silent-denial hazard above, one level deeper: it would tell an Owner "you may
 * not send this" when the truth is "this was already sent", which is the one
 * diagnosis they need. What actually prevents a second send is the check
 * constraint on `blasts` and the lock the sending path will carry, neither of
 * which is a courtesy. Trigger: Step 4, which owns the sending path and decides
 * where the state guard lives and what it reports.
 */
class BlastPolicy
{
    /**
     * See the blasts the campaign has written.
     */
    public function viewAny(User $operator): bool
    {
        return $operator->can(Permission::ViewBlasts->value);
    }

    /**
     * Write a new blast.
     */
    public function create(User $operator): bool
    {
        return $operator->can(Permission::EditBlasts->value);
    }

    /**
     * Change a blast already written, including who it is aimed at.
     *
     * The blast is not consulted, for two reasons that are worth telling apart.
     * Campaign isolation here is physical, exactly as it is for a supporter:
     * blasts live in the campaign's own database, so there is no campaign_id on
     * the row to compare against and no way for one campaign's blast to reach
     * another campaign's operator. And whether the blast is still a draft is
     * state rather than authority — see the note on this class.
     */
    public function update(User $operator, Blast $blast): bool
    {
        return $operator->can(Permission::EditBlasts->value);
    }

    /**
     * Commit the blast to sending.
     *
     * The one ability here the two roles disagree about, and the first anywhere
     * in this application withheld because the act cannot be undone rather than
     * because of the leverage it confers. Answered from its own permission
     * rather than from EditBlasts, which is the choice that makes the split
     * real: reusing the compose grant would have made this the fourth ability
     * in a row that discriminates nobody.
     *
     * The blast is an argument even though nothing is read off it, unlike
     * import() and export() on the supporter policy, which take none. Those are
     * about the list rather than about anybody on it; this is about one
     * particular message, and a signature that took no blast would authorize
     * "sending" in general — which is not a thing an operator ever does.
     */
    public function send(User $operator, Blast $blast): bool
    {
        return $operator->can(Permission::SendBlasts->value);
    }
}
