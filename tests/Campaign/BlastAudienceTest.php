<?php

declare(strict_types=1);

use App\Blasts\BlastAudience;
use App\Models\Blast;
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
