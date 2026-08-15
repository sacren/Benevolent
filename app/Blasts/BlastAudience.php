<?php

declare(strict_types=1);

namespace App\Blasts;

use App\Districts\DistrictClaim;
use App\Districts\DistrictNarrowing;
use App\Districts\ZctaDistricts;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\Supporter;
use App\Segments\SegmentNarrowing;
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
 * from that moment this class reads what was frozen and never the segment --
 * `blasts.committed_prefixes` for a segment of ZIP code prefixes and
 * `blasts.committed_zip_codes` for one narrowing by district (D-38), the column
 * being what says which rule replays it. The two halves are not a compromise
 * between them: a draft is a document the campaign may still change, and
 * everything past draft is a record of something it cannot.
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
     * **An aim now has two shapes, and this is where the shape picks the reader
     * (D-38).** A rule of ZIP code prefixes is answered by
     * App\Supporters\PostcodeNarrowing and a district by
     * App\Districts\DistrictNarrowing, which disagree about a stored
     * `02141abc` -- the first reaches it through `02141` and the second refuses
     * to claim anything for a value that is not a ZIP code. So this method
     * returns a query rather than composing one from a list of prefixes: a
     * shared return type of `list<string>` could only have carried one of the
     * two rules, and the caller would have had to guess which.
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

        // A blast carrying its own rule, which is prefixes and can be nothing
        // else: `blasts` has no column in which a blast could name a district
        // of its own, and D-37 gave the district half to segments deliberately.
        if ($blast->segment_id === null) {
            return PostcodeNarrowing::apply($query, $blast->postcode_prefixes ?? []);
        }

        // **A committed blast reads what it froze, and the branch is drawn on
        // the status rather than on a frozen column being populated (D-27).**
        // The two would be equivalent for every row the database will hold,
        // since `blasts_committed_aim_is_frozen` ties a frozen rule to exactly
        // the committed segment-aimed rows -- but they fail differently, and
        // only one of them fails safely. Asking whether a frozen rule is
        // present would let a committed blast that somehow lacked one fall
        // through to the live segment below, which is silently the exact defect
        // these columns exist to close. Asking the status means a committed
        // blast never reads a segment at all.
        if ($blast->status->isCommitted()) {
            return self::replayFrozenAim($query, $blast);
        }

        return self::followSegment($query, $blast);
    }

    /**
     * The audience a committed blast froze, replayed by the rule that froze it.
     *
     * **The column a frozen value sits in is what says how to replay it, and
     * that is the whole of D-38's reader question.** `committed_zip_codes` holds
     * whole ZIP codes the relation claimed for a seat, and only
     * DistrictNarrowing may answer them: replaying them through
     * PostcodeNarrowing would compare leading characters, so a supporter stored
     * as `02141abc` would be reached by a narrowing that the supporter list's
     * District column says is not in the district at all. `committed_prefixes`
     * holds prefixes, which are a postal question claiming no district, and
     * PostcodeNarrowing is what a prefix means (D-24, D-29).
     *
     * **Neither column populated reaches nobody, which is the branch above
     * being kept honest.** The check constraint makes that row
     * unrepresentable, so this is what the application does if a future writer
     * forgets or the constraint is dropped -- and it falls to nobody rather
     * than to the whole list, because over-inclusion is the direction a send
     * cannot take back.
     *
     * @param  Builder<Supporter>  $query
     * @return Builder<Supporter>
     */
    private static function replayFrozenAim(Builder $query, Blast $blast): Builder
    {
        $zipCodes = $blast->committed_zip_codes;

        if ($zipCodes !== null) {
            return DistrictNarrowing::toZipCodes($query, $zipCodes);
        }

        return PostcodeNarrowing::apply($query, $blast->committed_prefixes ?? []);
    }

    /**
     * The audience a draft's segment names, as it stands at the moment of
     * asking.
     *
     * Read, never copied -- for a draft, which is the only thing that still
     * points. An operator correcting a segment corrects every draft aimed at
     * it, which is what makes a pointer worth having.
     *
     * **Composed by App\Segments\SegmentNarrowing rather than here, and that
     * is D-29 applied to the segment's own rule.** That class is where a
     * segment's two kinds are turned into a query for the supporter list and
     * the export, and a second reading of `segments.district` in this file
     * would be a copy free to drift from it -- with the two disagreeing being a
     * blast reaching people the list said were somebody else's constituents. It
     * narrows to nobody for a segment naming a seat the relation does not, and
     * for one carrying neither rule.
     *
     * **The empty audience returned for a segment that will not resolve is the
     * safety here, and it is not a formality.** A segment reached through a
     * pointer cannot be missing -- `blasts.segment_id` restricts on delete --
     * so that branch is unreachable through a stored row. It is written anyway,
     * and it narrows to nobody rather than returning the untouched query,
     * because the two possible spellings of "I could not resolve the aim"
     * differ by the entire supporter list. The cost of the wrong one is a
     * message in every supporter's inbox, so it is spelled the safe way, and a
     * test drives it by building the state through the model rather than
     * through the table.
     *
     * **It is written as an explicit branch rather than as `?->` with a
     * fallback, because static analysis reads the relation as never null and
     * refuses the shorter spelling.** That disagreement is worth recording
     * rather than silencing: the analyser is describing the schema, which is
     * right, and the test is describing a model somebody built by hand, which
     * is also right.
     *
     * **The segment is fetched through the relation's query rather than through
     * `$blast->segment`, and the reason is that this is the one place a draft's
     * rule must not be cached.** The magic property memoizes on the instance,
     * so a caller that asked once and asked again would be answered from before
     * the segment moved. Asking the relation costs one query per blast per
     * operation, which is one, and buys the property that gives a pointer its
     * whole value.
     *
     * @param  Builder<Supporter>  $query
     * @return Builder<Supporter>
     */
    private static function followSegment(Builder $query, Blast $blast): Builder
    {
        $segment = $blast->segment()->first();

        if ($segment === null) {
            return $query->whereRaw('false');
        }

        return SegmentNarrowing::apply($query, $segment);
    }

    /**
     * The rule to freeze onto this blast as the campaign commits it, and which
     * column it belongs in (D-27, D-38).
     *
     * **Null for a blast whose aim cannot move, and that is the first half of
     * what this answers.** A blast carrying its own `postcode_prefixes` already
     * has its rule on its own row, where the only thing that could change it is
     * its own compose form -- and `refuseCommitted()` closes that the moment the
     * blast leaves draft. A blast aimed at nothing has no rule to freeze. The
     * one aim that can move under a committed blast is a segment's, because a
     * segment is shared and stays editable by design, so that is the one this
     * returns.
     *
     * **The second half is which of the two frozen columns the commit must
     * fill, and it is answered here because the reader is chosen by the column
     * (D-38).** Exactly one key is non-null in every returned pair, matching
     * what `blasts_committed_aim_is_frozen` requires of the row: a segment of
     * prefixes freezes the prefixes it names, and a segment of a district
     * freezes the ZIP codes the shipped relation claims for its seat, because
     * the seat's name would describe a different audience after any release
     * that replaces the relation.
     *
     * **It is here rather than in the controller because the resolution below
     * is the same resolution `for()` performs**, and D-29's whole finding is
     * that a rule with two spellings is a rule its two readers are free to
     * disagree about. A controller that read the segment itself would be a
     * second copy of this, free to drift from the one the send uses -- and the
     * two disagreeing is precisely a message going somewhere the campaign did
     * not commit it to.
     *
     * @return array{committed_prefixes: list<string>|null, committed_zip_codes: list<string>|null}|null
     */
    public static function committedAimFor(Blast $blast): ?array
    {
        if ($blast->segment_id === null) {
            return null;
        }

        $segment = $blast->segment()->first();

        // A pointer that resolves to nothing freezes an empty rule rather than
        // no rule, for followSegment()'s reason one screen up: an empty list
        // reaches nobody where a null would leave the row refused by the check
        // constraint, and of the two a committed blast that reaches nobody is
        // the one that does not also break the page it was committed from.
        if ($segment === null) {
            return ['committed_prefixes' => [], 'committed_zip_codes' => null];
        }

        if ($segment->postcode_prefixes !== null) {
            return ['committed_prefixes' => $segment->postcode_prefixes, 'committed_zip_codes' => null];
        }

        return ['committed_prefixes' => null, 'committed_zip_codes' => self::claimableZipCodesFor($segment)];
    }

    /**
     * The ZIP codes the shipped relation claims for a district segment's seat.
     *
     * Read through App\Districts\ZctaDistricts, which is the only reader of
     * the relation (D-34), and answered as an empty list for a seat the
     * relation does not name -- a list that narrows to nobody, never to
     * everybody.
     *
     * @return list<string>
     */
    private static function claimableZipCodesFor(Segment $segment): array
    {
        $relation = ZctaDistricts::shipped();
        $seat = $segment->seat($relation);

        return $seat === null ? [] : DistrictClaim::claimableIn($seat, $relation);
    }

    /**
     * How many supporters this blast would reach if it went out now.
     */
    public static function size(Blast $blast): int
    {
        return self::for($blast)->count();
    }
}
