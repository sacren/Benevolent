<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\User;

/**
 * What an operator may do to the supporters of the campaign they are in.
 *
 * Every answer comes from a Permission, never from reading the operator's
 * role. That is the whole reason the permission vocabulary exists: asking "may
 * this operator remove a supporter?" survives the day a third role appears,
 * while asking "is this operator an Owner?" has to be found and rewritten
 * everywhere someone wrote it — and a policy is the shortest place to write it,
 * which is exactly why the temptation lands here.
 *
 * **This class lives with its module rather than in app/Policies/, and that is
 * load-bearing rather than tidy.** Gate::getPolicyFor() consults an explicit
 * registration, then the #[UsePolicy] attribute, then path guessing. Measured
 * against this application: with no policy anywhere, the guess for
 * App\Models\Supporter is App\Models\Policies\SupporterPolicy; declare a class
 * at App\Policies\SupporterPolicy and the gate resolves it *with no attribute
 * at all*. So a policy filed by convention is wired by string substitution, and
 * the attribute above the model would be decorative — you could delete it and
 * every test would stay green. Here the attribute is the only thing connecting
 * the two, so deleting it turns the allow tests red, which is the only way to
 * know the wiring was ever doing anything. (The deny tests do not move, for the
 * reason recorded in tests/Campaign/SupporterAuthorizationTest.php.)
 *
 * **This policy answers exactly the six abilities below, and any other ability
 * checked against a Supporter is denied — silently, and indistinguishably from
 * a considered refusal.** (The count was wrong here until Step 5 read it: it
 * said four while there were five, because Step 4 added `import` without
 * touching this sentence. A count in prose is a claim about code that nothing
 * runs, so it is exactly the kind that rots — kept anyway, because knowing the
 * set is closed is what makes the paragraph worth reading, and a wrong number
 * is at least visible next to the methods.)
 *
 * Measured, not assumed: an ability the policy has no method for returns false
 * and throws the same AuthorizationException as an ability that never existed,
 * so a surface asking for one gets a plausible 403 rather than an error naming
 * the cause. `view` is deliberately absent because this module ships no
 * single-supporter page for it to govern. Adding any
 * surface that needs an ability not listed here means adding the method *and*
 * its allow test together; the missing method will not announce itself.
 */
class SupporterPolicy
{
    /**
     * See the campaign's supporter list.
     */
    public function viewAny(User $operator): bool
    {
        return $operator->can(Permission::ViewSupporters->value);
    }

    /**
     * Add a supporter to the campaign's list.
     */
    public function create(User $operator): bool
    {
        return $operator->can(Permission::EditSupporters->value);
    }

    /**
     * Change a supporter already on the list.
     *
     * The supporter is not consulted, and that is deliberate rather than
     * unfinished. Campaign isolation here is physical: supporters live in the
     * campaign's own database, so there is no campaign_id on the row to compare
     * against and no way for one campaign's supporter to be handed to another
     * campaign's operator in the first place. An ownership check written here
     * would have nothing to check and would read as though it were protecting
     * something.
     */
    public function update(User $operator, Supporter $supporter): bool
    {
        return $operator->can(Permission::EditSupporters->value);
    }

    /**
     * Bring a whole list into the campaign at once.
     *
     * Its own ability rather than a reuse of create(), even though both answer
     * from EditSupporters today and so cannot be told apart by any test. Two
     * reasons, and neither is tidiness. An import is not "create" -- it adds
     * people *and* corrects people already on the list, so authorizing it as
     * creation describes half of what it does. And it is the one ability here
     * whose answer is most likely to move: taking a campaign's whole list in
     * one action is exactly the sort of thing a director might reserve, and the
     * day that happens the change is one line here rather than an untangling of
     * two meanings sharing one method.
     *
     * The Supporter is not an argument because there is no supporter yet -- the
     * file names people the campaign may never have heard of.
     */
    public function import(User $operator): bool
    {
        return $operator->can(Permission::EditSupporters->value);
    }

    /**
     * Take the campaign's whole list out of the campaign, as one file.
     *
     * The second ability here the two roles disagree about, and the first one
     * whose grant this module chose rather than inherited. Owner-only, on the
     * same regret asymmetry that settles delete() below — and on the honest
     * weaker claim recorded with the permission itself: Staff already read
     * every supporter on the list page, so this withholds the one action that
     * turns the list into a portable file, not the reading of it.
     *
     * Answered from its own permission rather than from ViewSupporters, which
     * is the choice that makes the split real. Reusing the view grant would
     * have made this the fifth ability in a row that discriminates nobody.
     *
     * No Supporter argument, for the reason import() gives: this ability is
     * about the list rather than about anybody on it.
     */
    public function export(User $operator): bool
    {
        return $operator->can(Permission::ExportSupporters->value);
    }

    /**
     * Remove a supporter from the campaign permanently.
     *
     * The other ability here the two roles disagree about. The supporter is
     * unconsulted for the same reason as update() above.
     */
    public function delete(User $operator, Supporter $supporter): bool
    {
        return $operator->can(Permission::DeleteSupporters->value);
    }
}
