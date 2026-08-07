<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Blasts\BlastStatus;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The list of messages a campaign has written, over HTTP, on the campaign's own
 * hostname, signed in.
 *
 * tests/Campaign/BlastStorageTest.php asks what the table holds and
 * tests/Campaign/BlastAuthorizationTest.php asks who may do what. This file
 * asks whether the page an operator actually opens consults any of it.
 */

test('an operator sees the messages this campaign has written', function (): void {
    Blast::factory()->create(['subject' => 'Object before Friday']);
    Blast::factory()->sent()->create(['subject' => 'Thank you for objecting']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('blasts/Index')
            ->has('blasts', 2)
        );
});

test('the newest message is first, with the id breaking a tie', function (): void {
    // Written in the same second, which is the case an order without a tie-break
    // gets wrong: `created_at` is a timestamp two rows can share, and an order
    // that is not total lets them swap places between requests.
    $moment = now();

    $first = Blast::factory()->create(['subject' => 'Written first', 'created_at' => $moment]);
    $second = Blast::factory()->create(['subject' => 'Written second', 'created_at' => $moment]);

    expect($second->getKey())->toBeGreaterThan($first->getKey());

    // And an older one, so the test says something about the date half too
    // rather than only about the tie-break.
    Blast::factory()->create(['subject' => 'Written last week', 'created_at' => $moment->copy()->subWeek()]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.subject', 'Written second')
            ->where('blasts.1.subject', 'Written first')
            ->where('blasts.2.subject', 'Written last week')
        );
});

test('the page carries the audience rule and the state, because that is what the list is for', function (): void {
    Blast::factory()->narrowedToPostcodes(['M15', 'sw1a'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            // Null would be a blast aimed at everybody, so the prefixes have to
            // arrive as the list they are rather than as a rendered string the
            // server decided on: the page says what null means in words, and it
            // cannot do that if it is handed words either way.
            ->where('blasts.0.postcode_prefixes', ['M15', 'sw1a'])
            ->where('blasts.0.status', BlastStatus::Draft->value)
        );
});

test('a campaign with nothing written yet is still a page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('blasts', 0));
});

test('a guest is sent to sign in rather than shown the list', function (): void {
    Blast::factory()->create();

    // The sibling of SupporterListTest's guest guard, and it is not decoration:
    // without it, dropping `auth` from this route leaves the whole suite green.
    // Measured -- the route moved out of the ['auth', 'verified'] group reddened
    // nothing at all, because every other test here signs in first and an
    // anonymous request would then be refused by the gate rather than sent to
    // sign in. A 403 where a redirect belongs is a working authorization system
    // hiding a missing authentication one.
    //
    // route() rather than campaignUrl() for the expectation: tenancy is
    // initialized, so the generator already produces the campaign's own host,
    // and it includes the port that campaignUrl() does not.
    $this->get($this->campaignUrl('/blasts'))
        ->assertRedirect(route('login'));
});

test('the list refuses an operator who has lost the grant', function (): void {
    // The deny half, and it cannot fail on its own: a route that 403'd at
    // everybody, or one that did not exist, would satisfy this exactly as a
    // working guard does. What makes it evidence is every other test in this
    // file, where the identical request succeeds.
    //
    // Both roles hold ViewBlasts today, so the refusal has to be built rather
    // than found: the grant is withdrawn for the length of this test. That is
    // deliberately the *permission* being withdrawn rather than the policy being
    // stubbed, because it is the shape of the real change -- a role losing a
    // grant -- and it proves the controller consults the policy rather than
    // waving every signed-in operator through. The idiom is SupporterListTest's.
    Gate::define(Permission::ViewBlasts->value, fn (): bool => false);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertForbidden();
});

test('the list says which named narrowing a blast is aimed at', function (): void {
    // The page renders this cell from what arrives here, so what arrives has to
    // be the segment rather than only the pointer: `segment_id` is an id and
    // names nothing an operator can read.
    $segment = Segment::factory()->narrowedToPostcodes(['M15'])->create(['name' => 'Whalley Range']);

    Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.segment_id', $segment->getKey())
            ->where('blasts.0.segment.name', 'Whalley Range')
            // And the blast carries no rule of its own, which is the pair that
            // makes the page's ordering matter: a summary reading the column
            // first would call this blast "everyone subscribed".
            ->where('blasts.0.postcode_prefixes', null)
        );
});

test('the narrowing is loaded once for the page, not once per blast', function (): void {
    // The distinction this page is built on, asserted rather than described.
    // An audience count per row is refused here on a measurement; a segment per
    // row would be the same shape one table along, and eager loading is what
    // makes it one query for the whole list however many blasts there are.
    $first = Segment::factory()->create(['name' => 'Ardwick']);
    $second = Segment::factory()->create(['name' => 'Whalley Range']);

    Blast::factory()->aimedAtSegment($first)->create();
    Blast::factory()->aimedAtSegment($second)->create();
    Blast::factory()->aimedAtSegment($first)->create();

    $operator = User::factory()->create();

    $segmentQueries = 0;

    DB::listen(function ($query) use (&$segmentQueries): void {
        if (str_contains($query->sql, '"segments"')) {
            $segmentQueries++;
        }
    });

    $this->actingAs($operator)
        ->get($this->campaignUrl('/blasts'))
        ->assertOk();

    // One for three blasts pointing at two segments. Written as an exact
    // number rather than "fewer than three", because the claim is that the
    // count does not grow with the list and an inequality would still hold
    // for a page that queried twice.
    expect($segmentQueries)->toBe(1);
});
