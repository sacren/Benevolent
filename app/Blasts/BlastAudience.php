<?php

declare(strict_types=1);

namespace App\Blasts;

use App\Models\Blast;
use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a blast would go to.
 *
 * The reading half of D-14. A blast stores a *rule* rather than a list of
 * people, and this is the one place that rule is turned into a query -- so the
 * count an operator is shown before sending and the set a send actually walks
 * come from the same lines of code rather than from two that agree today.
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
     * The stored postcode, folded so that two spellings of one postcode match.
     *
     * **This is not a `like` pattern, and that is a correctness decision rather
     * than a stylistic one.** Measured against postcodes written the four ways
     * a real list writes them:
     *
     *   - `where postcode like 'M15%'` finds `M15 6BH` and `M156BH` and misses
     *     `m15 6bh` entirely; `'m15%'` finds only the lowercase one.
     *   - An operator who types `%` selects **every supporter with a
     *     postcode** -- a control whose whole purpose is to narrow a blast,
     *     quietly widening it to the entire list. `_` matches any single
     *     character in the same way.
     *
     * Both are metacharacters in the pattern rather than input to it, and
     * escaping them is a thing the query builder does not do. Comparing a fixed
     * number of leading characters for equality has no pattern language to
     * escape, so a `%` matches a postcode that starts with a literal `%` and
     * nothing else -- which is what the operator asked for.
     *
     * **Why `replace` rather than `regexp_replace(..., '\s', ...)`.** Both fold
     * the space, which is the inconsistency this column actually carries. On
     * 250,000 supporters, a subscribed-only count is 61.4 ms; adding one folded
     * prefix costs 100.6 ms with `replace` and 519.7 ms with `regexp_replace`,
     * and three prefixes cost 249.7 ms against 498.8 ms. Both read the same
     * `Buffers: shared hit=3796` under the same Parallel Seq Scan -- the
     * difference is entirely the per-row cost of the fold, paid 250,000 times.
     *
     * What the cheaper one gives up is tabs and newlines. **Neither folds a
     * non-breaking space** -- measured, all three spellings leave U+00A0 where
     * it is -- so completeness was never on the table and the choice was which
     * incomplete rule to pay five times over for. **Trigger to revisit:** the
     * first list whose postcodes are separated by something other than a space.
     *
     * This corrects a line in D-14's own resolution, which recorded that adding
     * the postcode predicate made the count *cheaper* because "they are the same
     * scan". The scan half is exactly right and the cheaper half is not true of
     * the case-and-space-insensitive form this actually needs.
     */
    private const string FOLDED_POSTCODE = "replace(lower(postcode), ' ', '')";

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
        // direction as the fail-closed case below.
        if ($prefixes === null) {
            return $query;
        }

        $folded = array_values(array_filter(
            array_map(self::fold(...), $prefixes),
            static fn (string $prefix): bool => $prefix !== '',
        ));

        if ($folded === []) {
            // A blast that names an aim which none of `left()` can use matches
            // **nobody**, and never everybody. A prefix that folds away to
            // nothing -- an operator who typed only spaces -- would otherwise
            // reach this method as an empty narrowing and be indistinguishable
            // from null, which is the same wildcard `%` would have been by
            // another route. Over-inclusion is the direction that cannot be
            // taken back once a send has run, so an unusable aim fails closed.
            //
            // ComposeBlastRequest drops blank prefixes before any of this is
            // stored, so this is the second line rather than the first. It is
            // here because the column is the input: a seeder, a factory or a
            // hand-written row reaches this method without passing through a
            // form at all.
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $narrowed) use ($folded): void {
            foreach ($folded as $prefix) {
                // left() counts characters rather than bytes, which is the same
                // thing mb_strlen() counts, so a multi-byte prefix compares
                // against the same number of characters it was measured as.
                $narrowed->orWhereRaw(
                    'left('.self::FOLDED_POSTCODE.', ?) = ?',
                    [mb_strlen($prefix), $prefix],
                );
            }
        });
    }

    /**
     * How many supporters this blast would reach if it went out now.
     */
    public static function size(Blast $blast): int
    {
        return self::for($blast)->count();
    }

    /**
     * Fold an operator's prefix the same way the column is folded.
     *
     * **The two folds have to agree, so they are written next to each other.**
     * A prefix folded differently from the column is a filter that matches
     * nothing while looking entirely correct -- the failure mode D-8's address
     * matching already produced once, where a query compared a raw value
     * against a folded index and reported that somebody on the list was not.
     */
    private static function fold(string $prefix): string
    {
        return str_replace(' ', '', mb_strtolower($prefix));
    }
}
