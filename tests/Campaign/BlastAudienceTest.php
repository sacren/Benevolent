<?php

declare(strict_types=1);

use App\Blasts\BlastAudience;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;

/*
 * Who a blast would go to.
 *
 * The first thing in this product's life to put `subscription_status` and
 * `postcode` into a `where`, which is Phase 2 exit criterion 2 -- and the file
 * that has to be right, because every defect here is a message sent to somebody
 * who should not have received it or withheld from somebody who should.
 *
 * A helper rather than a factory state, because every test below wants a
 * postcode written a particular way and a status set deliberately.
 *
 * Named for what it varies rather than for what it makes. A global function
 * called `supporter()` would be the first name any later blast or supporter
 * test reaches for, and a second declaration of it is a fatal redeclare rather
 * than a failing test -- the constraint tests/Pest.php already records against
 * refusalFrom(). If a second file ever wants this, it moves there.
 */
function supporterWithPostcode(?string $postcode, SubscriptionStatus $status = SubscriptionStatus::Subscribed): Supporter
{
    return Supporter::factory()->create([
        'postcode' => $postcode,
        'subscription_status' => $status,
    ]);
}

test('a blast with no narrowing reaches everyone the campaign may contact, and nobody else', function (): void {
    $reachable = supporterWithPostcode('M15 6BH');
    supporterWithPostcode('M15 6BH', SubscriptionStatus::Unsubscribed);
    $noPostcode = supporterWithPostcode(null);

    $blast = Blast::factory()->create();

    // Both directions in one assertion, so a later edit cannot drop the half
    // that does the work: everyone subscribed is in, including somebody with no
    // postcode at all, and the unsubscribed supporter is out.
    expect(BlastAudience::for($blast)->pluck('id')->all())
        ->toEqualCanonicalizing([$reachable->getKey(), $noPostcode->getKey()])
        ->and(BlastAudience::size($blast))->toBe(2);
});

test('somebody who asked not to be contacted is left out, and no blast can ask for them', function (): void {
    // The one guard in this step that is not in the reversible tier. A compose
    // surface that lets an operator aim at unsubscribed people is a defect Step
    // 4 would then execute, and there is no unsending it.
    //
    // The status condition is not a parameter of BlastAudience::for(), there is
    // no argument that turns it off, and `blasts` carries no column that could
    // record the intention -- so this is asserted through the only entry point
    // there is.
    supporterWithPostcode('M15 6BH', SubscriptionStatus::Unsubscribed);
    supporterWithPostcode('M15 9AA', SubscriptionStatus::Unsubscribed);

    $everyone = Blast::factory()->create();
    $narrowed = Blast::factory()->narrowedToPostcodes(['M15'])->create();

    expect(BlastAudience::size($everyone))->toBe(0)
        ->and(BlastAudience::size($narrowed))->toBe(0);
});

test('a postcode prefix matches however the source spelled the postcode', function (): void {
    // The four spellings one real list carries. Phase 1 stored the postcode
    // exactly as given, on the grounds that a normalized value is recoverable
    // from the raw and not the reverse; this is where that bill arrives.
    $spellings = ['M15 6BH', 'm15 6bh', 'M156BH', '  m15  6bh '];

    foreach ($spellings as $spelling) {
        supporterWithPostcode($spelling);
    }

    // And two that must not be swept in with them.
    supporterWithPostcode('M16 7AB');
    supporterWithPostcode('EH8 9YL');

    $blast = Blast::factory()->narrowedToPostcodes(['M15'])->create();

    expect(BlastAudience::size($blast))->toBe(count($spellings));
});

test('the operator\'s own prefix is folded the same way the column is', function (): void {
    supporterWithPostcode('M156BH');

    // The prefix carries a space the stored postcode does not, which is the
    // mirror of the previous test: folding only the column would leave this
    // matching nothing while looking perfectly correct.
    $spaced = Blast::factory()->narrowedToPostcodes(['m15 6'])->create();
    $shouted = Blast::factory()->narrowedToPostcodes(['M15 6BH'])->create();

    expect(BlastAudience::size($spaced))->toBe(1)
        ->and(BlastAudience::size($shouted))->toBe(1);
});

test('several prefixes widen the aim, and only to what they name', function (): void {
    supporterWithPostcode('M15 6BH');
    supporterWithPostcode('EH8 9YL');
    supporterWithPostcode('SW1A 1AA');

    $blast = Blast::factory()->narrowedToPostcodes(['M15', 'eh8'])->create();

    expect(BlastAudience::for($blast)->pluck('postcode')->all())
        ->toEqualCanonicalizing(['M15 6BH', 'EH8 9YL']);
});

test('a supporter with no postcode is out of a narrowed blast and in an unnarrowed one', function (): void {
    // Ordinary rather than a corner: a petition widget that asked only for an
    // email produces exactly this row, and the campaign can still contact them.
    // They just cannot be aimed at by postcode.
    supporterWithPostcode(null);

    $narrowed = Blast::factory()->narrowedToPostcodes(['M15'])->create();
    $everyone = Blast::factory()->create();

    expect(BlastAudience::size($narrowed))->toBe(0)
        ->and(BlastAudience::size($everyone))->toBe(1);
});

test('a wildcard character is a character, not a wildcard', function (): void {
    // Measured, and the reason the match is not written with `like`: under
    // `where postcode like '%'` every supporter carrying any postcode at all is
    // selected, so a control whose entire purpose is to narrow a blast would
    // quietly widen it to the whole list. `_` does the same for a single
    // character.
    //
    // These are metacharacters in the pattern rather than input to it, and the
    // query builder escapes neither.
    supporterWithPostcode('M15 6BH');
    supporterWithPostcode('EH8 9YL');
    supporterWithPostcode('%oddly enough');

    $percent = Blast::factory()->narrowedToPostcodes(['%'])->create();
    $underscore = Blast::factory()->narrowedToPostcodes(['M1_'])->create();

    // The percent matches the one postcode that genuinely starts with a percent
    // sign, which is the positive half: an assertion that it matched *nothing*
    // would also pass against a query that is simply broken.
    expect(BlastAudience::for($percent)->pluck('postcode')->all())->toBe(['%oddly enough'])
        ->and(BlastAudience::size($underscore))->toBe(0);
});

test('an aim that names nothing usable reaches nobody, never everybody', function (): void {
    // The same wildcard by another route, and the one that arrives without any
    // metacharacter at all: `left(postcode, 0) = ''` is true of every non-null
    // postcode, so a prefix that folds away to nothing would be an empty
    // narrowing indistinguishable from "no narrowing at all".
    //
    // Over-inclusion is the direction that cannot be taken back once a send has
    // run, so an unusable aim fails closed. Stated against a stored value the
    // form would never produce, because the column is the input: a seeder, a
    // factory or a hand-written row reaches the audience without passing
    // through a form.
    supporterWithPostcode('M15 6BH');
    supporterWithPostcode('EH8 9YL');

    $blank = Blast::factory()->narrowedToPostcodes(['   '])->create();
    $empty = Blast::factory()->narrowedToPostcodes([])->create();
    $everyone = Blast::factory()->create();

    expect(BlastAudience::size($blank))->toBe(0)
        // A stored empty list is the same claim and gets the same answer. It
        // had a branch of its own until breaking that branch reddened nothing,
        // which is how it was found: it made an aim naming no prefixes mean
        // *everybody*, while an aim naming an unusable one meant nobody.
        ->and(BlastAudience::size($empty))->toBe(0)
        // Paired with the case it must not be confused with, in the same run:
        // an empty list of aims is not the same claim as no aim at all.
        ->and(BlastAudience::size($everyone))->toBe(2);
});

test('a blast aimed at a segment reaches the people that segment names', function (): void {
    $inside = supporterWithPostcode('M15 6BH');
    supporterWithPostcode('EH8 9YL');

    $segment = Segment::factory()->narrowedToPostcodes(['M15'])->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    // The same two directions the unnarrowed test asserts together, so a later
    // edit cannot drop the half that does the work.
    expect(BlastAudience::for($blast)->pluck('id')->all())->toBe([$inside->getKey()])
        ->and(BlastAudience::size($blast))->toBe(1);
});

test('a segment is read when the audience is asked, never copied when the blast was aimed', function (): void {
    // **The assertion that makes a pointer a pointer**, and §7's fifth
    // criterion names the alternative as an illegitimate way to satisfy it: a
    // convenience that copied the segment's prefixes onto the blast would pass
    // the test above and fail this one, while leaving the product with the two
    // narrowing mechanisms this phase exists to join up.
    supporterWithPostcode('M15 6BH');
    $moved = supporterWithPostcode('EH8 9YL');

    $segment = Segment::factory()->narrowedToPostcodes(['M15'])->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    expect(BlastAudience::size($blast))->toBe(1);

    // The campaign re-aims the segment. Nothing about the blast's own row
    // changes -- and this is the whole of D-27's exposure stated as a fact
    // rather than as a worry: for a draft it is correct and is the point, and
    // for a committed blast it is the question Step 5 owns.
    $segment->update(['postcode_prefixes' => ['EH8']]);

    expect(BlastAudience::for($blast->refresh())->pluck('id')->all())
        ->toBe([$moved->getKey()]);
});

test('a segment cannot widen a blast past the people who may be contacted', function (): void {
    // **Exit criterion 3, the half this step owes**, and it is a different
    // claim from the two already paid. Step 1 proved a segment's stored rule
    // *cannot say* anything about subscription -- there is no column for it.
    // Step 3 proved the supporter list may legitimately show somebody who
    // unsubscribed. This is the third: reaching the rule through a segment does
    // not carry the list's permission with it, because subscribed-only is this
    // class's shape rather than a parameter anything passes.
    supporterWithPostcode('M15 6BH', SubscriptionStatus::Unsubscribed);
    supporterWithPostcode('M15 9AA', SubscriptionStatus::Unsubscribed);
    $reachable = supporterWithPostcode('M15 1AA');

    $segment = Segment::factory()->narrowedToPostcodes(['M15'])->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    // The same segment narrows a supporter list to all three of these people,
    // which is the asymmetry: one stored rule, two readers, two different
    // guarantees around it.
    expect(BlastAudience::for($blast)->pluck('id')->all())->toBe([$reachable->getKey()])
        ->and(BlastAudience::size($blast))->toBe(1);
});

test('a segment naming nothing usable reaches nobody, never everybody', function (): void {
    // PostcodeNarrowing's fail-closed case reached through the pointer rather
    // than through the column, because the two arrive by different branches and
    // only one of them was ever tested. A segment whose rule folds away is the
    // pointer's version of the wildcard: the aim names nothing `left()` can
    // use, and the safe answer is nobody.
    supporterWithPostcode('M15 6BH');
    supporterWithPostcode('EH8 9YL');

    $blank = Segment::factory()->narrowedToPostcodes(['   '])->create();
    $blast = Blast::factory()->aimedAtSegment($blank)->create();

    // Paired with the case it must not be confused with, in the same run: a
    // blast naming no aim at all still reaches everybody, so this is not
    // passing against an audience that is simply broken.
    $everyone = Blast::factory()->create();

    expect(BlastAudience::size($blast))->toBe(0)
        ->and(BlastAudience::size($everyone))->toBe(2);
});

test('a pointer that resolves to no segment reaches nobody, never everybody', function (): void {
    // **The one branch in this class the database cannot reach, driven through
    // the model instead.** `blasts.segment_id` restricts on delete and
    // `segments.postcode_prefixes` is NOT NULL, so no stored row can carry a
    // pointer that resolves to nothing -- which means the fallback protecting
    // that case is unguarded unless something builds the state directly.
    //
    // It is worth guarding rather than deleting because of what the two
    // spellings of "I could not resolve the aim" differ by. Written as an empty
    // list it reaches nobody; written as null it would arrive back at the
    // widening branch and reach every supporter the campaign may contact. The
    // difference is the entire list, and the direction that cannot be taken
    // back is the one a dropped constraint would open.
    supporterWithPostcode('M15 6BH');
    supporterWithPostcode('EH8 9YL');

    $dangling = new Blast;
    $dangling->segment_id = 9_999_999;

    // Paired with the case it must not be confused with, through the same class
    // in the same run: a blast naming no aim at all still reaches everybody, so
    // this is not passing against an audience that is simply broken.
    $everyone = Blast::factory()->create();

    expect(BlastAudience::size($dangling))->toBe(0)
        ->and(BlastAudience::size($everyone))->toBe(2);
});
