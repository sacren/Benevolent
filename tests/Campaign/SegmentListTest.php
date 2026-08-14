<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The list of narrowings a campaign has named, over HTTP, on the campaign's own
 * hostname, signed in.
 *
 * tests/Campaign/SegmentStorageTest.php asks what the table holds and
 * tests/Campaign/SegmentAuthorizationTest.php asks who may do what. This file
 * asks whether the page an operator actually opens consults any of it -- and it
 * is the first thing in this application to consult SegmentPolicy at all, which
 * until now was exercised only by tests.
 */

test('an operator sees the narrowings this campaign has named', function (): void {
    Segment::factory()->create(['name' => 'Harbor precinct']);
    Segment::factory()->create(['name' => 'Dockside streets']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('segments/Index')
            ->has('segments', 2)
        );
});

test('the segments are ordered by name, which is already a total order', function (): void {
    // Named out of order and created out of order, so neither arrival nor
    // insertion could produce this result by accident.
    Segment::factory()->create(['name' => 'Westwood']);
    Segment::factory()->create(['name' => 'Pasadena']);
    Segment::factory()->create(['name' => 'Somerville']);

    // No tie-break is asserted because there is nothing to tie: `segments.name`
    // carries a unique index, so ordering by it is total. That is the property
    // the two sibling lists lack -- both order by a `created_at` two rows can
    // share, which is why both carry an id tie-break and this page does not.
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('segments.0.name', 'Pasadena')
            ->where('segments.1.name', 'Somerville')
            ->where('segments.2.name', 'Westwood')
        );
});

test('the page carries the rule as a list, because that is what the page is for', function (): void {
    Segment::factory()->narrowedToPostcodes(['902', '6060'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertInertia(fn (Assert $page) => $page
            // The prefixes arrive as the list they are rather than as a string
            // the server rendered, for the reason the blast list gives: the page
            // has to be able to say that a rule naming nothing reaches nobody,
            // and it cannot tell that from a rule naming something if it is
            // handed words either way.
            ->where('segments.0.postcode_prefixes', ['902', '6060'])
        );
});

test('a campaign that has named nothing is still a page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('segments', 0));
});

test('a guest is sent to sign in rather than shown the list', function (): void {
    Segment::factory()->create();

    // Not decoration, and the sibling lists measured why: with the route moved
    // out of the ['auth', 'verified'] group the rest of this file stays green,
    // because every other test signs in first and an anonymous request would
    // then be refused by the gate rather than sent to sign in. A 403 where a
    // redirect belongs is a working authorization system hiding a missing
    // authentication one.
    $this->get($this->campaignUrl('/segments'))
        ->assertRedirect(route('login'));
});

test('the list refuses an operator who has lost the grant', function (): void {
    // The deny half, and it cannot fail on its own: a route that 403'd at
    // everybody, or one that did not exist, would satisfy this exactly as a
    // working guard does. What makes it evidence is every other test in this
    // file, where the identical request succeeds.
    //
    // **No segment ability discriminates between the two roles (D-25), so this
    // refusal has to be built rather than found** -- the grant is withdrawn for
    // the length of this test. That is deliberately the *permission* being
    // withdrawn rather than the policy being stubbed, because it is the shape of
    // the real change, and because it is what proves the controller consults the
    // policy rather than waving every signed-in operator through.
    //
    // ViewSupporters rather than a segment permission of its own, which is D-25
    // as behaviour: an operator who may not read the campaign's supporters may
    // not read the ways it has been narrowed either.
    Gate::define(Permission::ViewSupporters->value, fn (): bool => false);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertForbidden();
});

test('a district segment is listed with the Congress whose map it is read against', function (): void {
    Segment::factory()->inDistrict('MA-07')->create(['name' => 'MA-07 supporters']);
    // California has 52 seats: what a stored seat becomes when a later map
    // drops it, which the page must be able to say reaches nobody.
    $unnamed = Segment::factory()->inDistrict('CA-53')->create(['name' => 'Gone']);
    Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Westwood']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('segments.1.name', 'MA-07 supporters')
            ->where('segments.1.district', 'MA-07')
            ->where('segments.1.postcode_prefixes', null)
            ->where('districts', ['congress' => '119th', 'unnamed' => [$unnamed->getKey()]])
        );
});

test('a campaign with no district segment is not sent a map to read them against', function (): void {
    // The relation costs about 14 ms and 11 MB to read, and a list of prefix
    // segments has no use for it.
    Segment::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments'))
        ->assertInertia(fn (Assert $page) => $page->where('districts', null));
});
