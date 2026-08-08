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
 * **What a postcode prefix means is not decided here (D-29).** The fold and the
 * leading-character comparison live in App\Supporters\PostcodeNarrowing,
 * because D-24 promoted that rule from an implementation detail of this class
 * to the product's definition of what a prefix *is*, and a definition with two
 * spellings is one its readers may disagree about. What stayed here is
 * everything that is true of a *blast's* audience and of nothing else:
 * subscribed-only, and where the blast's rule is found.
 *
 * **Where the rule is found is now two places, and this class is the only one
 * that puts them together (D-26).** A blast either points at a segment the
 * campaign named or carries its own `postcode_prefixes`; the database forbids
 * both at once. So the aim is resolved here, once, and every caller keeps
 * asking the same two questions it always asked.
 *
 * **The widening branch is the whole risk of that change and is drawn first,
 * deliberately.** Exactly one state means "everybody this campaign may
 * contact": neither column set. Everything else is an aim, and an aim is
 * honoured or it reaches nobody -- so a pointer that resolved to nothing
 * narrows to nobody rather than falling through to the whole list. That
 * ordering is not defensive tidiness: `restrictOnDelete` on the column makes a
 * dangling pointer unreachable through the database, and writing the branch the
 * other way round would put the product one dropped constraint away from
 * mailing a campaign's entire list.
 *
 * **A draft's segment is read, never copied; a committed blast's rule was
 * copied and is never read again (D-27).** For a draft the rule is the
 * segment's as it stands at the moment of asking, which is what makes a pointer
 * a pointer -- an operator correcting a segment corrects every draft aimed at
 * it. For a blast the campaign has committed, that same liveness was a defect
 * rather than a feature: SendBlast consumes this query when the job runs rather
 * than when the campaign committed, so a segment edited in between sent the
 * message to a different set of people. It re-resolves on every attempt, so the
 * exposure was the whole life of a send and not a window at the start of it.
 *
 * So the aim is frozen onto the blast by the statement that commits it, and
 * from that moment this class reads `blasts.committed_prefixes` and never the
 * segment. The two halves are not a compromise between them: a draft is a
 * document the campaign may still change, and everything past draft is a record
 * of something it cannot.
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

        // **Naming neither a segment nor a rule, and only that, is the
        // campaign's whole contactable list.** That is the migration's own
        // contract for these two columns, and the one branch here that widens
        // rather than narrows, so it is drawn as narrowly as it can be:
        // everything else is an aim, and an aim is honoured or it reaches
        // nobody.
        //
        // An earlier draft also let a stored empty list mean "everybody", on
        // the reading that a list of no prefixes narrows nothing. Breaking that
        // branch reddened nothing at all, which is what exposed it: it made `[]`
        // mean everybody while `['  ']` -- equally an aim that names nothing
        // usable -- meant nobody, and the widening half was the unguarded one.
        // One rule, drawn on the absence of both columns, is both simpler and
        // safe in the same direction as PostcodeNarrowing's own fail-closed
        // case.
        if ($blast->segment_id === null && $blast->postcode_prefixes === null) {
            return $query;
        }

        return PostcodeNarrowing::apply($query, self::prefixesFor($blast));
    }

    /**
     * The prefixes this blast's aim currently names.
     *
     * Only ever called for a blast that has an aim, because the widening branch
     * above has already returned for the one that does not -- which is why
     * every path out of here is a narrowing and none of them can be mistaken
     * for "no rule".
     *
     * **The empty list returned for a segment that will not resolve is the
     * safety here, and it is not a formality.** A segment reached through a
     * pointer cannot be missing -- `blasts.segment_id` restricts on delete, and
     * `segments.postcode_prefixes` is NOT NULL -- so that branch is unreachable
     * through a stored row. It is written anyway, and written as an empty list
     * rather than as null, because the two possible spellings of "I could not
     * resolve the aim" differ by the entire supporter list: an empty list
     * reaches nobody through PostcodeNarrowing's own fail-closed case, while a
     * null would arrive back at the widening branch above. The cost of the
     * wrong one is a message in every supporter's inbox, so it is spelled the
     * safe way, and a test drives it by building the state through the model
     * rather than through the table.
     *
     * **It is written as an explicit branch rather than as `?->` with a
     * fallback, because static analysis reads the relation as never null and
     * refuses the shorter spelling.** That disagreement is worth recording
     * rather than silencing: the analyser is describing the schema, which is
     * right, and the test is describing a model somebody built by hand, which
     * is also right. The branch below satisfies both and reads more plainly for
     * a safety this consequential.
     *
     * **The segment is fetched through the relation's query rather than through
     * `$blast->segment`, and the reason is that this is the one place a draft's
     * rule must not be cached.** The magic property memoizes on the instance,
     * so a caller that asked once and asked again would be answered from before
     * the segment moved. Asking the relation costs one query per blast per
     * operation, which is one, and buys the property that gives a pointer its
     * whole value.
     *
     * @return list<string>
     */
    private static function prefixesFor(Blast $blast): array
    {
        if ($blast->segment_id !== null) {
            // **A committed blast reads what it froze, and the branch is drawn
            // on the status rather than on the column being populated (D-27).**
            // The two are equivalent for any row the database will hold, since
            // `blasts_committed_aim_is_frozen` ties them together -- but they
            // fail differently, and only one of them fails safely. Asking
            // whether a frozen rule is present would let a committed blast that
            // somehow lacked one fall through to the live segment below, which
            // is silently the exact defect this column exists to close. Asking
            // the status means a committed blast never reads a segment at all,
            // and a missing frozen rule reaches nobody instead.
            if ($blast->status->isCommitted()) {
                return $blast->committed_prefixes ?? [];
            }

            // Read, never copied -- for a draft, which is the only thing that
            // still points. The rule is the segment's as it stands at the
            // moment of asking, which is what makes the pointer worth having.
            $segment = $blast->segment()->first();

            if ($segment === null) {
                return [];
            }

            return $segment->postcode_prefixes;
        }

        return $blast->postcode_prefixes ?? [];
    }

    /**
     * The rule to freeze onto this blast as the campaign commits it (D-27).
     *
     * **Null for a blast whose aim cannot move, and that is the whole of what
     * this answers.** A blast carrying its own `postcode_prefixes` already has
     * its rule on its own row, where the only thing that could change it is its
     * own compose form -- and `refuseCommitted()` closes that the moment the
     * blast leaves draft. A blast aimed at nothing has no rule to freeze. The
     * one aim that can move under a committed blast is a segment's, because a
     * segment is shared and stays editable by design, so that is the one this
     * returns.
     *
     * **It is here rather than in the controller because the resolution below
     * is the same resolution `for()` performs**, and D-29's whole finding is
     * that a rule with two spellings is a rule its two readers are free to
     * disagree about. A controller that read the segment itself would be a
     * second copy of `prefixesFor()`, free to drift from the one the send uses
     * -- and the two disagreeing is precisely a message going somewhere the
     * campaign did not commit it to.
     *
     * @return list<string>|null
     */
    public static function committedAimFor(Blast $blast): ?array
    {
        if ($blast->segment_id === null) {
            return null;
        }

        return self::prefixesFor($blast);
    }

    /**
     * How many supporters this blast would reach if it went out now.
     */
    public static function size(Blast $blast): int
    {
        return self::for($blast)->count();
    }
}
