<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\Supporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrowing a query over supporters to the postcodes a campaign named.
 *
 * **The product's matching rule, in the one place it is written down (D-24,
 * D-29, amended by D-33).** A prefix matches a supporter when
 * `left(replace(postcode, ' ', ''), n)` equals it. Until this class existed
 * that spelling lived inside App\Blasts\BlastAudience, where it was an
 * implementation detail of one class; D-24 promoted it to what a postcode
 * prefix *means* in this product, and a meaning with two spellings is a meaning
 * the two readers are free to disagree about.
 *
 * **The prediction this docblock used to carry came true, which is why the rule
 * above is shorter than it was.** It said: *the day this product chooses a
 * jurisdiction, the fold changes, and a second copy is the one nobody edits.*
 * The jurisdiction was chosen at Phase 4 -- the United States, congressional
 * districts -- and the fold did change. Because there was only ever one copy,
 * the change was one constant and one method. That is D-29 paying out, and it
 * is recorded here rather than in a plan because this is where the next reader
 * stands.
 *
 * **This is the *matcher*, and it is deliberately not the *storage*.** Where a
 * rule is kept was D-26, answered at Step 4 as shape (b): a blast points at a
 * segment *or* carries its own `postcode_prefixes`, and the column stayed. The
 * two are independent axes, and separating them is what let one be settled
 * without committing the other -- this class was extracted a step before that
 * answer and needed no change when it arrived, because it takes a plain list of
 * prefixes and knows nothing about where they were kept.
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
     * than a stylistic one.**
     *
     *   - `where postcode like '90210%'` misses `90210 1234` and `90210-1234`
     *     entirely, because the space and the hyphen sit where the pattern
     *     expects digits. Folding first and comparing a fixed number of leading
     *     characters catches all three spellings.
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
     *
     * **The space fold survives the jurisdiction change on a different
     * justification, and that was measured rather than assumed (D-33).** It was
     * added because a UK postcode carries an internal space. A US ZIP does not
     * -- but a ZIP+4 is written `90210 1234` as readily as `90210-1234`, and a
     * list arrives with surrounding whitespace either way. Folded, all of
     * `90210 1234`, `902101234` and `  90210  1234 ` become `902101234`, so one
     * prefix reaches every spelling. The hyphen needs no folding at all,
     * because `left(..., 5)` recovers the ZIP-5 from `90210-1234` regardless.
     *
     * **`lower()` was removed here, and it was removed on a measurement rather
     * than on tidiness (D-33).** It earned its place against UK postcodes, which
     * carry letters. A US ZIP is five digits, and `lower('90210')` is `90210`
     * for every input the product can now hold -- so the call could not change
     * an outcome and no test could ever have made it fail. Deleting it from
     * both halves while the UK corpus was still in place reddened 10 of 497
     * tests, every one of them narrowing against a letter-bearing postcode;
     * deleting it after the corpus became US ZIPs reddens nothing, which is the
     * proof that nothing was carrying it. **A limb of the product's own
     * definitional rule that cannot fail is worse than most**, because the next
     * reader assumes it was measured.
     *
     * **What this gives up, stated rather than smoothed:** a stored value that
     * does contain letters -- a Canadian postal code in a border district's
     * list, or a typo -- no longer matches a prefix spelled in the other case.
     * The product is US-only and has no fixture for that, which is exactly why
     * the line could not be tested; **trigger to revisit: the first list this
     * product is asked to hold whose postcodes are not US ZIPs.**
     *
     * **Public because a narrowing by district folds the column too**, and a
     * second spelling of the fold would be a second place for the two to
     * drift apart. App\Districts\DistrictNarrowing reads it from here.
     */
    public const string FOLDED_POSTCODE = "replace(postcode, ' ', '')";

    /**
     * Narrow a supporter query to the supporters those prefixes name.
     *
     * Takes and returns a query rather than building one, so the caller owns
     * what else is true of the set. BlastAudience hands in a query that is
     * already subscribed-only; the supporter list and its export hand in ones
     * that are not, because an operator correcting a record has to be able to
     * find somebody who unsubscribed. This class does not need to know which it
     * was given.
     *
     * (That sentence said "the only caller is BlastAudience" until now. It was
     * already untrue when Step 3 wrote it -- the same step added the other two
     * callers -- and is corrected here rather than left standing beside a
     * paragraph above that has just been brought up to date.)
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
        return str_replace(' ', '', $prefix);
    }
}
