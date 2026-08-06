<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\Supporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrowing a query over supporters to the postcodes a campaign named.
 *
 * **The product's matching rule, in the one place it is written down (D-24,
 * D-29).** A prefix matches a supporter when
 * `left(replace(lower(postcode), ' ', ''), n)` equals it. Until this class
 * existed that spelling lived inside App\Blasts\BlastAudience, where it was an
 * implementation detail of one class; D-24 promoted it to what a postcode
 * prefix *means* in this product, and a meaning with two spellings is a meaning
 * the two readers are free to disagree about. That is not hypothetical: the day
 * this product chooses a jurisdiction, the fold changes, and a second copy is
 * the one nobody edits -- after which "everyone in M15" names two different
 * sets of people depending on which surface asked. D-8 closed exactly that shape
 * for addresses.
 *
 * **This is the *matcher*, and it is deliberately not the *storage*.** Where a
 * rule is kept -- on a blast, in a segment, or in one place both point at -- is
 * D-26 and belongs to Step 4. The two are independent axes, and the whole reason
 * this class exists is that extracting one of them does not commit the other:
 * `blasts.postcode_prefixes` is exactly where Step 3 found it, and BlastAudience
 * still reads its own column.
 *
 * **What this class deliberately does NOT do, and both omissions are
 * correctness-critical in opposite directions.**
 *
 *   - **It does not filter by subscription status.** Subscribed-only is
 *     BlastAudience's invariant, applied to the query before it arrives here,
 *     and it must not travel: the supporter list may legitimately show somebody
 *     who unsubscribed -- an operator correcting a record has to be able to find
 *     them -- so a narrowed list that hid them would be *wrong* rather than
 *     safe. One rule, two readers, two different guarantees around it.
 *   - **It has no "narrow by nothing" branch.** A null `blasts.postcode_prefixes`
 *     means "every supporter this campaign may contact", and that widening is
 *     the blast column's own contract, drawn as narrowly as it can be in the one
 *     place that owns it. `segments.postcode_prefixes` is NOT NULL precisely so
 *     that branch is unreachable through a segment. A matcher that widened on an
 *     empty rule would put it back.
 */
final class PostcodeNarrowing
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
     *     postcode** -- a control whose whole purpose is to narrow, quietly
     *     widening to the entire list. `_` matches any single character in the
     *     same way.
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
     */
    private const string FOLDED_POSTCODE = "replace(lower(postcode), ' ', '')";

    /**
     * Narrow a supporter query to the supporters those prefixes name.
     *
     * Takes and returns a query rather than building one, so the caller owns
     * what else is true of the set. Today the only caller is BlastAudience,
     * which hands in a query that is already subscribed-only; a caller that
     * wants the unsubscribed too hands in one that is not, and this class does
     * not need to know which it was given.
     *
     * @param  Builder<Supporter>  $query
     * @param  list<string>  $prefixes
     * @return Builder<Supporter>
     */
    public static function apply(Builder $query, array $prefixes): Builder
    {
        $folded = self::folded($prefixes);

        if ($folded === []) {
            // A rule naming nothing `left()` can use matches **nobody**, and
            // never everybody. A prefix that folds away to nothing -- an
            // operator who typed only spaces -- would otherwise arrive as an
            // empty narrowing and be indistinguishable from no narrowing at
            // all, which is the same wildcard `%` would have been by another
            // route. Over-inclusion is the direction that cannot be taken back
            // once a send has run, so an unusable rule fails closed.
            //
            // ComposeBlastRequest drops blank prefixes before a blast's rule
            // is ever stored, so for that caller this is the second line rather
            // than the first, and any later caller owes the same check at its
            // own form. It is here regardless because the column is the input:
            // a seeder, a factory or a hand-written row reaches this method
            // without passing through a form at all.
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
     * The prefixes that can narrow anything, folded the way the column is.
     *
     * **The two folds have to agree, so they are written next to each other.**
     * A prefix folded differently from the column is a filter that matches
     * nothing while looking entirely correct -- the failure mode D-8's address
     * matching already produced once, where a query compared a raw value
     * against a folded index and reported that somebody on the list was not.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private static function folded(array $prefixes): array
    {
        return array_values(array_filter(
            array_map(self::fold(...), $prefixes),
            static fn (string $prefix): bool => $prefix !== '',
        ));
    }

    /**
     * Fold one of an operator's prefixes.
     */
    private static function fold(string $prefix): string
    {
        return str_replace(' ', '', mb_strtolower($prefix));
    }
}
