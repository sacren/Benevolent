<?php

declare(strict_types=1);

namespace App\Blasts;

use App\Models\Blast;
use App\Models\Supporter;
use App\Supporters\PostcodeNarrowing;
use App\Supporters\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a blast would go to.
 *
 * The reading half of D-14. A blast stores a *rule* rather than a list of
 * people, and this is the one place a *blast's* rule is turned into a query --
 * so the count an operator is shown before sending and the set a send actually
 * walks come from the same lines of code rather than from two that agree today.
 *
 * **What a postcode prefix means is no longer decided here (D-29).** The fold
 * and the leading-character comparison moved to App\Supporters\PostcodeNarrowing
 * when a second reader arrived, because D-24 promoted that rule from an
 * implementation detail of this class to the product's definition of what a
 * prefix *is*, and a definition with two spellings is one the two readers may
 * disagree about. What stayed here is everything that is true of a *blast's*
 * audience and of nothing else: subscribed-only, and the meaning of a null
 * column. **This moved the matcher and deliberately not the storage** -- whether
 * a blast keeps its own `postcode_prefixes` is D-26, owned by Step 4, and this
 * class still reads that column exactly where it always did.
 *
 * **A count taken from here is a prediction, not a promise, and any surface
 * showing one has to say so.** The rule is evaluated again when sending starts,
 * so somebody who unsubscribes in between is correctly left out of the send and
 * the number moves. That is the cost D-14 accepted deliberately in exchange for
 * never mailing somebody who asked not to be contacted, and it must not be
 * "fixed" by materializing a list at compose time.
 *
 * **Subscribed-only is enforced here rather than chosen, and the shape of this
 * class is what enforces it.** It is not a parameter, there is no argument that
 * turns it off, and `blasts` carries no column that could record an intention
 * to reach people who unsubscribed. An operator narrows a blast; they do not
 * widen one.
 */
final class BlastAudience
{
    /**
     * The supporters this blast would reach if it went out now.
     *
     * Returns a query rather than a result so that the same rule can be counted
     * for a compose page and walked in chunks by a send, which is what keeps
     * "who we told you it would go to" and "who it went to" the same question.
     *
     * @return Builder<Supporter>
     */
    public static function for(Blast $blast): Builder
    {
        $query = Supporter::query()
            ->where('subscription_status', SubscriptionStatus::Subscribed);

        $prefixes = $blast->postcode_prefixes;

        // **Null, and only null, is the campaign's whole contactable list.**
        // That is the migration's own contract for this column, and the one
        // branch here that widens rather than narrows, so it is drawn as
        // narrowly as it can be: everything else is an aim, and an aim is
        // honoured or it reaches nobody.
        //
        // An earlier draft also let a stored empty list mean "everybody", on
        // the reading that a list of no prefixes narrows nothing. Breaking that
        // branch reddened nothing at all, which is what exposed it: it made `[]`
        // mean everybody while `['  ']` -- equally an aim that names nothing
        // usable -- meant nobody, and the widening half was the unguarded one.
        // One rule, drawn on null, is both simpler and safe in the same
        // direction as PostcodeNarrowing's own fail-closed case.
        //
        // This branch is why the matcher has none of its own: `segments`
        // declares the same column NOT NULL, so widening cannot be reached
        // through a segment at all, and a matcher that widened on an empty rule
        // would put that branch back where nobody asked for it.
        if ($prefixes === null) {
            return $query;
        }

        return PostcodeNarrowing::apply($query, $prefixes);
    }

    /**
     * How many supporters this blast would reach if it went out now.
     */
    public static function size(Blast $blast): int
    {
        return self::for($blast)->count();
    }
}
