<?php

declare(strict_types=1);

use App\Models\Segment;
use App\Models\Supporter;
use App\Segments\SegmentNarrowing;

/*
 * The one place the supporter list and its export turn a segment into a query.
 *
 * A segment holds ZIP code prefixes or a district (D-37), and each is answered
 * by its own class -- PostcodeNarrowingTest and DistrictNarrowingTest own what
 * each rule means. What this file owns is the choice between them, and what
 * happens when there is nothing to choose: every way of not being able to
 * answer narrows to nobody.
 */

test('a segment is answered by the rule it holds', function (): void {
    $prefixMatch = Supporter::factory()->create(['postcode' => '02141abc']);
    $placed = Supporter::factory()->create(['postcode' => '02141']);
    Supporter::factory()->create(['postcode' => '90232']);

    // The same leading characters, two kinds of rule, two different answers:
    // the prefix reaches the value that only begins with a ZIP code, and the
    // district does not, because it is not a ZIP code (D-37).
    $byPrefix = Segment::factory()->narrowedToPostcodes(['02141'])->create();
    $byDistrict = Segment::factory()->inDistrict('MA-07')->create();

    expect(SegmentNarrowing::apply(Supporter::query(), $byPrefix)->pluck('id')->all())
        ->toEqualCanonicalizing([$prefixMatch->getKey(), $placed->getKey()])
        ->and(SegmentNarrowing::apply(Supporter::query(), $byDistrict)->pluck('id')->all())
        ->toBe([$placed->getKey()]);
});

test('a segment holding neither rule narrows to nobody, never to everybody', function (): void {
    // Unreachable through a stored row -- `segments_narrow_one_way_only`
    // refuses it -- so the state is built through the model, which is how
    // BlastAudienceTest drives the fallback its database cannot reach.
    Supporter::factory()->count(3)->create(['postcode' => '02141']);

    $neither = new Segment;
    $neither->postcode_prefixes = null;
    $neither->district = null;

    // Paired with a segment that does name somewhere, through the same call.
    $somewhere = Segment::factory()->inDistrict('MA-07')->create();

    expect(SegmentNarrowing::apply(Supporter::query(), $neither)->count())->toBe(0)
        ->and(SegmentNarrowing::apply(Supporter::query(), $somewhere)->count())->toBe(3);
});

test('a district segment naming a seat the map does not have narrows to nobody', function (): void {
    Supporter::factory()->count(3)->create(['postcode' => '02141']);

    expect(SegmentNarrowing::apply(Supporter::query(), Segment::factory()->inDistrict('CA-53')->create())->count())->toBe(0);
});
