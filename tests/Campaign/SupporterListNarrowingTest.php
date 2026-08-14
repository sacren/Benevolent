<?php

declare(strict_types=1);

use App\Models\Segment;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The supporter list narrowed to a segment, over HTTP.
 *
 * **This is the file exit criterion 2 is confirmed against.** Before this step
 * SupporterController::index() consumed no request input that changed which rows
 * came back, so a campaign with twelve thousand supporters paged through them in
 * arrival order and could do nothing else. What that criterion forbids is
 * satisfying it with a control that exists on the page and narrows nothing, so
 * every test here asserts the *rows*, never the control.
 *
 * tests/Campaign/PostcodeNarrowingTest.php owns what a prefix means. This file
 * owns what the page does with it: which rows, which count, what survives
 * paging, and what an unusable or unknown narrowing does.
 */

test('the list narrows to the segment the request names', function (): void {
    $inside = Supporter::factory()->create(['postcode' => '90210']);
    $alsoInside = Supporter::factory()->create(['postcode' => '90210 1234']);
    Supporter::factory()->create(['postcode' => '02139']);
    Supporter::factory()->create(['postcode' => null]);

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Cambridge']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment='.$segment->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('supporters/Index')
            ->has('supporters.data', 2)
            // The total is the narrowed total rather than the campaign's, which
            // is what the heading reads from: a page saying "4 people" over two
            // rows would be describing a different question than the one asked.
            ->where('supporters.total', 2)
            ->where('narrowedTo', $segment->getKey())
        );

    expect(Supporter::query()->count())->toBe(4)
        ->and([$inside->getKey(), $alsoInside->getKey()])->toHaveCount(2);
});

test('a narrowed list still shows somebody who unsubscribed', function (): void {
    // **The asymmetry, asserted on the surface rather than only in the matcher.**
    // A blast may never reach somebody who asked not to be contacted; the
    // supporter list may legitimately show them, because an operator correcting
    // a record has to be able to find them. A narrowed list that quietly hid
    // them would be *wrong* rather than safe -- and it is the mistake a reader
    // makes by assuming one stored rule means the same thing to both consumers.
    Supporter::factory()->create([
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);
    Supporter::factory()->create([
        'postcode' => '90211',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment='.$segment->getKey()))
        ->assertInertia(fn (Assert $page) => $page->where('supporters.total', 2));
});

test('the list is not narrowed when the request names no segment', function (): void {
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '02139']);

    Segment::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('supporters.total', 2)
            ->where('narrowedTo', null)
            // The campaign's segments reach the page whether or not one is in
            // use, because the control that aims the list has to list them.
            ->has('segments', 1)
        );
});

test('a segment whose rule names nothing usable narrows to nobody, never to everybody', function (): void {
    // The fail-closed rule on the surface that consumes it. The form refuses
    // this shape, so the row is built directly -- which is the point: the column
    // is the input, and a seeder, a factory or a hand-written row reaches the
    // page without passing through a form at all.
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '02139']);

    $blank = Segment::factory()->narrowedToPostcodes(['   '])->create(['name' => 'Typed only spaces']);
    $empty = Segment::factory()->narrowedToPostcodes([])->create(['name' => 'Named nothing']);

    $operator = User::factory()->create();

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters?segment='.$blank->getKey()))
        ->assertInertia(fn (Assert $page) => $page->where('supporters.total', 0));

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters?segment='.$empty->getKey()))
        ->assertInertia(fn (Assert $page) => $page->where('supporters.total', 0));

    // Paired with the case it must not be confused with, in the same run, so
    // that a page which narrowed to nobody unconditionally could not pass.
    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters'))
        ->assertInertia(fn (Assert $page) => $page->where('supporters.total', 2));
});

test('a segment that does not exist is refused rather than answered with the whole list', function (): void {
    Supporter::factory()->count(3)->create();

    // **The widening this step's own router could have produced.** A stale link
    // or a mistyped URL naming no segment must not fall through to "no
    // narrowing": that hands an operator every supporter under a heading saying
    // the list is narrowed, which is the `%` metacharacter's failure arriving
    // through the router. 404 is the only answer that cannot be mistaken for a
    // result.
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment=999999'))
        ->assertNotFound();
});

test('a segment named with something that is not a number is refused before it reaches a query', function (): void {
    // `segments.id` is a bigint, so comparing it against `abc` raises SQLSTATE
    // 22P02 rather than matching no rows -- a 500 for anybody who mistypes a
    // link, with the offending value inlined into the exception message. The
    // same trap `whereUuid` closes on the unsubscribe routes.
    $operator = User::factory()->create();

    foreach (['abc', '1; drop table supporters', '../1', '1.5'] as $malformed) {
        $this->actingAs($operator)
            ->get($this->campaignUrl('/supporters?segment='.urlencode($malformed)))
            ->assertNotFound();
    }
});

test('the narrowing survives paging, on the links the page actually carries', function (): void {
    // **A filter that silently dropped on page two is a defect visible only on
    // page two**, which is why this is asserted by following the page's own
    // link rather than by constructing a URL. `withQueryString()` was already on
    // this action before the step; what is new is that it now carries something,
    // and "already there" is not evidence that it works.
    Supporter::factory()->count(60)->create(['postcode' => '90210']);
    Supporter::factory()->count(10)->create(['postcode' => '02139']);

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $nextPage = null;

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment='.$segment->getKey()))
        ->assertInertia(function (Assert $page) use (&$nextPage): void {
            $page->where('supporters.total', 60)
                ->has('supporters.data', 50);

            $nextPage = $page->toArray()['props']['supporters']['next_page_url'];
        });

    expect($nextPage)->toBeString()
        ->and($nextPage)->toContain('segment=');

    $this->actingAs(User::factory()->create())
        ->get((string) $nextPage)
        ->assertInertia(fn (Assert $page) => $page
            ->where('supporters.total', 60)
            // Ten of the sixty, and none of the Edinburgh rows: a narrowing
            // dropped here would show 70 in total and put them on this page.
            ->has('supporters.data', 10)
        );
});

test('a list narrowed to a district segment shows the supporters placed in it, and says what it leaves out', function (): void {
    $inside = Supporter::factory()->create(['postcode' => '02141']);
    $alsoInside = Supporter::factory()->create(['postcode' => '02115-1234']);
    // Crosses MA-07's boundary with MA-05: may be in it, so never shown as in it.
    Supporter::factory()->create(['postcode' => '02139']);
    Supporter::factory()->create(['postcode' => '90232']);
    // Begins with an MA-07 ZIP code and is not one: a prefix segment on `02141`
    // would show this row, and a district segment does not (D-37).
    Supporter::factory()->create(['postcode' => '02141abc']);

    $segment = Segment::factory()->inDistrict('MA-07')->create(['name' => 'MA-07 supporters']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment='.$segment->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('supporters/Index')
            ->where('supporters.total', 2)
            ->where('supporters.data.0.id', $alsoInside->getKey())
            ->where('supporters.data.1.id', $inside->getKey())
            // Counted from the shipped file with python: MA-07 holds 17 ZCTAs
            // whole and 26 more cross its boundary.
            ->where('districts.narrowing', [
                'district' => 'MA-07',
                'seat' => 'MA-07',
                'wholly' => 17,
                'crossing' => 26,
            ])
            // And every row the narrowing reached is one the district column
            // places in the same seat, which is the two readers agreeing.
            ->where('districts.bySupporter.'.$inside->getKey().'.claimed', 'MA-07')
            ->where('districts.bySupporter.'.$alsoInside->getKey().'.claimed', 'MA-07')
        );
});

test('a district segment naming a seat the map does not have narrows to nobody, and the page is told why', function (): void {
    Supporter::factory()->create(['postcode' => '02141']);
    Supporter::factory()->create(['postcode' => '90232']);

    // California has 52 seats. No form writes this -- a stored seat becomes it
    // when a later release ships a map that dropped it.
    $segment = Segment::factory()->inDistrict('CA-53')->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment='.$segment->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('supporters.total', 0)
            ->where('districts.narrowing', [
                'district' => 'CA-53',
                'seat' => null,
                'wholly' => 0,
                'crossing' => 0,
            ])
        );
});

test('a list narrowed by ZIP code prefixes has no district narrowing to explain', function (): void {
    Supporter::factory()->create(['postcode' => '02141']);

    $segment = Segment::factory()->narrowedToPostcodes(['021'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters?segment='.$segment->getKey()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('supporters.total', 1)
            ->where('districts.narrowing', null)
        );
});
