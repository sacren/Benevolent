<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Supporters\PostcodeNarrowing;
use App\Supporters\SubscriptionStatus;

/*
 * The product's matching rule, tested through the one class that owns it.
 *
 * **This file is not a second copy of BlastAudienceTest, and the difference is
 * the whole reason the matcher was extracted (D-29).** Every test in that file
 * reaches the fold through a query that is *already* subscribed-only, because
 * BlastAudience::for() opens with that condition and there is no argument that
 * turns it off. So no assertion there can distinguish a matcher that narrows on
 * postcode from one that also, quietly, filters by status -- and the supporter
 * list is about to hand this the opposite requirement.
 *
 * What this file asserts is therefore what that one structurally cannot: applied
 * to a bare query, the matcher narrows on postcode and on nothing else, and it
 * still fails closed.
 */

test('the matcher narrows on postcode and on nothing else', function (): void {
    // The asymmetry exit criterion 3 turns on, from the list's side. A blast
    // may never reach somebody who unsubscribed; the supporter list may
    // legitimately show them, because an operator correcting a record has to be
    // able to find them. One rule, two readers, two different guarantees around
    // it -- so the matcher must carry neither.
    $subscribed = Supporter::factory()->create([
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
    $unsubscribed = Supporter::factory()->create([
        'postcode' => '90210 1234',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);
    Supporter::factory()->create(['postcode' => '02139']);

    $narrowed = PostcodeNarrowing::apply(Supporter::query(), ['902']);

    // Both directions in one assertion, so a later edit cannot drop the half
    // that does the work: the unsubscribed supporter is in because status is
    // not this class's business, and the Cambridge ZIP code is out because the
    // prefix is.
    expect($narrowed->pluck('id')->all())
        ->toEqualCanonicalizing([$subscribed->getKey(), $unsubscribed->getKey()]);
});

test('a prefix matches however the source spelled the postcode', function (): void {
    // The four spellings one real list carries of a single ZIP+4. Phase 1 stored the postcode
    // exactly as given, on the grounds that a normalized value is recoverable
    // from the raw and not the reverse; this is the rule that pays that bill,
    // and it is asserted here rather than only through a blast because it is
    // now what a prefix *means* in this product rather than how one module
    // happens to query.
    foreach (['90210 1234', '902101234', ' 90210 1234 ', '90210  1234'] as $spelling) {
        Supporter::factory()->create(['postcode' => $spelling]);
    }

    Supporter::factory()->create(['postcode' => '91101']);
    Supporter::factory()->create(['postcode' => null]);

    // The operator's prefix carries a space the stored postcodes do not, which
    // is the mirror of the same claim: folding only the column would leave this
    // matching nothing while looking perfectly correct.
    expect(PostcodeNarrowing::apply(Supporter::query(), ['90210 1'])->count())->toBe(4)
        ->and(PostcodeNarrowing::apply(Supporter::query(), ['902', '911'])->count())->toBe(5);
});

test('a wildcard character is a character, not a wildcard', function (): void {
    // Measured at Phase 2 Step 3 and now guarded where the rule lives: under
    // `where postcode like '%'` every supporter carrying any postcode at all is
    // selected, so a control whose entire purpose is to narrow would quietly
    // widen to the whole list. `_` does the same for a single character. These
    // are metacharacters in the pattern rather than input to it, and the query
    // builder escapes neither.
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '%oddly enough']);

    // The percent matches the one postcode that genuinely starts with a percent
    // sign, which is the positive half: an assertion that it matched *nothing*
    // would also pass against a query that is simply broken.
    //
    // The underscore is aimed at `902_` because a wildcard there would reach
    // `90210`. It was `M1_` until Phase 4 Step 4, a UK prefix left behind by the
    // move to ZIP codes, and `'90210' like 'M1_%'` is false -- so under a rule
    // that let `_` through, this line stayed green and guarded nothing.
    expect(PostcodeNarrowing::apply(Supporter::query(), ['%'])->pluck('postcode')->all())
        ->toBe(['%oddly enough'])
        ->and(PostcodeNarrowing::apply(Supporter::query(), ['902_'])->count())->toBe(0);
});

test('a rule that names nothing usable narrows to nobody, never to everybody', function (): void {
    // The same wildcard by another route, and the one that arrives without any
    // metacharacter at all: `left(postcode, 0) = ''` is true of every non-null
    // postcode, so a prefix that folds away to nothing would be an empty
    // narrowing indistinguishable from no narrowing.
    //
    // Asserted here against a *bare* query, which is the case BlastAudience
    // cannot construct: there, an over-inclusive result is still bounded by
    // subscribed-only, so the failure would show as "every contactable
    // supporter" rather than as "everybody". Here it would be everybody.
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create([
        'postcode' => '02139',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    expect(PostcodeNarrowing::apply(Supporter::query(), ['   '])->count())->toBe(0)
        ->and(PostcodeNarrowing::apply(Supporter::query(), [])->count())->toBe(0)
        // Paired with the case it must not be confused with, in the same run,
        // so that a matcher which narrowed to nobody unconditionally could not
        // pass this file.
        ->and(PostcodeNarrowing::apply(Supporter::query(), ['902'])->count())->toBe(1);
});
