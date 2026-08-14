<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\User;
use App\Tenancy\CampaignSeat;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Naming a narrowing, re-aiming one, and removing one — over HTTP, on the
 * campaign's own hostname, signed in.
 *
 * tests/Campaign/SegmentListTest.php drives the read-only page. This file
 * drives the four actions that change something, at the verb and path
 * route:list reports for each, which is what makes exit criterion 1's "driven"
 * different from "inspected".
 */

test('an operator opens the form for naming a segment', function (): void {
    // **Added at the Phase 3 exit, where criterion 1 was found unmet on exactly
    // this route.** Every other route in this module had a test issuing its verb
    // at its path *and* asserting the success side; `GET /segments/create` had
    // only the 403 row in the authorization dataset below, and the browser suite
    // covers the list and the edit form but not this page. So the one route a
    // campaign uses to name its *first* segment -- the entry point of the whole
    // feature -- had no proof it renders for anybody.
    //
    // **The tell is the one Phase 2's exit recorded: adjacent proofs summing to
    // something that reads like the missing one.** SegmentAuthorizationTest
    // asserts `Gate::allows('create', Segment::class)` for both roles but issues
    // no request; the dataset below drives the route but asserts only that it
    // can be refused; and the sibling edit form does render and is tested. Read
    // together those look like coverage of this page, and none of them touches
    // it.
    //
    // This is also L-16's allow half for `create` at the HTTP boundary, paired
    // with the deny below through the same call.
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments/create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('segments/Create'));
});

test('an operator names a segment and it appears on the list', function (): void {
    $operator = User::factory()->create();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Harbor precinct',
            'postcode_prefixes' => '902, 021',
        ])
        ->assertRedirect(route('segments.index'));

    $segment = Segment::query()->sole();

    expect($segment->name)->toBe('Harbor precinct')
        // Stored as typed, unfolded, because the fold happens at match time
        // against a column that is itself unfolded. Storing them folded would
        // show an operator back a prefix they did not write.
        ->and($segment->postcode_prefixes)->toBe(['902', '021'])
        // Authorship comes from the signed-in operator rather than from the
        // form, which is what keeps it out of #[Fillable].
        ->and($segment->operator_id)->toBe($operator->getKey());
});

test('a form cannot claim that somebody else named a segment', function (): void {
    $operator = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Harbor precinct',
            'postcode_prefixes' => '902',
            'operator_id' => $someoneElse->getKey(),
        ])
        ->assertRedirect(route('segments.index'));

    // **This is green for a reason other than the one it looks like, and the
    // measurement is why it is written down rather than assumed.** Widening the
    // model's #[Fillable] to include `operator_id` leaves this assertion green,
    // at 0 red of 453 -- because NameSegmentRequest::named() is itself an
    // allowlist returning exactly the rule's keys (two until D-37 added a
    // district as the third), so the forged value never reaches
    // the model at all. Phase 2 Step 3 measured the identical thing about
    // ComposeBlastRequest and this reproduces it.
    //
    // So the behavioural half below says the surface is safe today, and the
    // configuration half beside it pins the thing that would be the last
    // defence if `named()` ever stopped being an allowlist -- a `create($request
    // ->all())`, or a key added to it. Laravel guards every attribute by
    // default, so the fillable list is what *permits* the ones that are there.
    expect(Segment::query()->sole()->operator_id)->toBe($operator->getKey())
        ->and((new Segment)->getFillable())->toBe(['name', 'postcode_prefixes', 'district']);
});

test('a segment must name at least one postcode, and separators are not postcodes', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Aimed at nobody',
            'postcode_prefixes' => ',,,',
        ])
        ->assertInvalid(['postcode_prefixes' => 'Name at least one ZIP code for this segment to narrow to.']);

    // `required` catches an empty field and cannot catch this one: `,,,` is a
    // perfectly good non-empty string that parses to no prefixes at all. The
    // column would accept the result as `[]`, the segment would exist and look
    // like a rule, and it would match nobody.
    //
    // Step 1 put this check on the form deliberately rather than in a check
    // constraint, on the ground that an empty rule is *safe* under the
    // fail-closed reading. This is that decision being honoured rather than
    // rediscovered.
    expect(Segment::query()->count())->toBe(0);
});

test('two segments cannot share a name, and the refusal is the form\'s rather than the database\'s', function (): void {
    Segment::factory()->create(['name' => 'Harbor precinct']);

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Harbor precinct',
            'postcode_prefixes' => '902',
        ])
        ->assertInvalid(['name']);

    // The distinction is the whole point, and Phase 1 paid for learning it. A
    // uniqueness rule that fails to catch the duplicate is not a missing
    // niceness: the insert then reaches the index and PostgreSQL answers 23505,
    // which is a 500 in an operator's face. There it happened because
    // `Rule::unique` compares the raw column while the index is on
    // `lower(email)`. Here Step 1 chose a plain unique index, so the framework's
    // own rule compares exactly what the index compares.
    expect(Segment::query()->count())->toBe(1);
});

test('a segment name differing only in case is a different name, in the form and in the database', function (): void {
    Segment::factory()->create(['name' => 'Culver City']);

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'culver city',
            'postcode_prefixes' => '9023',
        ])
        ->assertValid();

    // Pinned in both directions, so a later step that decides segment names
    // should fold reddens a line saying where the decision was made. Step 1
    // chose the plain index over `lower(name)` because a duplicate segment name
    // is *visible* -- both rows appear in the list somebody is reading at the
    // moment they choose -- where a duplicate address is silent.
    expect(Segment::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Culver City', 'culver city']);
});

test('an operator re-aims a segment, and the form is shown what is stored', function (): void {
    $segment = Segment::factory()->narrowedToPostcodes(['902', '911'])->create(['name' => 'Old name']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl("/segments/{$segment->getKey()}/edit"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('segments/Edit')
            ->where('segment.name', 'Old name')
            ->where('segment.postcode_prefixes', ['902', '911'])
        );

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl("/segments/{$segment->getKey()}"), [
            'name' => 'New name',
            'postcode_prefixes' => '6060',
        ])
        ->assertRedirect(route('segments.index'));

    expect($segment->refresh()->name)->toBe('New name')
        ->and($segment->postcode_prefixes)->toBe(['6060']);
});

test('re-aiming a segment without renaming it is not refused by its own name', function (): void {
    $segment = Segment::factory()->create(['name' => 'Harbor precinct']);

    // Without ignore(), the uniqueness rule would refuse this because the name
    // already belongs to a segment -- namely this one. The supporter module hit
    // exactly this and answered it with a second request class; one class
    // answers it here because nothing else about the two forms differs.
    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl("/segments/{$segment->getKey()}"), [
            'name' => 'Harbor precinct',
            'postcode_prefixes' => '902, 911',
        ])
        ->assertValid();

    expect($segment->refresh()->postcode_prefixes)->toBe(['902', '911']);
});

test('an operator removes a segment', function (): void {
    $segment = Segment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl("/segments/{$segment->getKey()}"))
        ->assertRedirect(route('segments.index'));

    expect(Segment::query()->count())->toBe(0);
});

test('every action refuses an operator who has lost the grant', function (string $verb, string $path): void {
    // The deny half for all four, and none of them can fail alone: a route that
    // 403'd at everybody would satisfy each exactly as a working guard does.
    // What makes them evidence is every test above, where the identical request
    // succeeds.
    //
    // EditSupporters rather than a permission of segments' own, which is D-25 as
    // behaviour -- including `delete`, which deliberately does *not* answer from
    // DeleteSupporters: removing a segment destroys no supporter, and anybody
    // who may edit one can already empty it.
    Gate::define(Permission::EditSupporters->value, fn (): bool => false);

    $segment = Segment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->call($verb, $this->campaignUrl(str_replace('{id}', (string) $segment->getKey(), $path)), [
            'name' => 'Renamed',
            'postcode_prefixes' => '902',
        ])
        ->assertForbidden();
})->with([
    'name a segment' => ['GET', '/segments/create'],
    'save a new segment' => ['POST', '/segments'],
    'open one for editing' => ['GET', '/segments/{id}/edit'],
    're-aim one' => ['PATCH', '/segments/{id}'],
    'remove one' => ['DELETE', '/segments/{id}'],
]);

test('a segment a blast is aimed at is not removed, and the operator is told why', function (): void {
    // **The refusal is the schema's and this is only its sentence.** Without
    // it the operator sees a 500: the foreign key restricts on delete, because
    // the two alternatives are both wrong in ways nothing reports -- nulling
    // would widen the blast to every supporter the campaign may contact, and
    // cascading would destroy the record of a message already sent.
    $segment = Segment::factory()->create(['name' => 'Beverly Hills']);
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl("/segments/{$segment->getKey()}"))
        // A redirect carrying the reason rather than a 403 or a 404. The
        // segment exists and the operator may remove segments; what has changed
        // is that something points at this one -- which is the same division
        // BlastController::refuseCommitted() makes for a committed blast.
        ->assertRedirect(route('segments.index'))
        // **The sentence, not just the redirect.** Until Step 6 this test's
        // name promised the operator was told why and nothing here read what
        // they were told, so the wording was free to say anything. The advice
        // is real in this case -- every blast aimed here is a draft, and
        // re-aiming one is a thing the application permits -- so it is asserted
        // word for word, and the committed case below asserts that this
        // sentence is *not* the one used there.
        ->assertInertiaFlash(
            'toast.message',
            'A blast is aimed at that segment, so it cannot be removed. Re-aim that blast first.',
        );

    // The claim, rather than the status code: nothing was removed and nothing
    // was re-aimed.
    expect(Segment::query()->count())->toBe(1)
        ->and($blast->fresh()->segment_id)->toBe($segment->getKey());
});

test('an owner cannot remove one either, because this is state and not authority', function (): void {
    // The refusal is not an authority the Owner can override, which is what
    // makes it different from every other refusal in this module. SegmentPolicy
    // answers who may act and never what may be acted on, so both roles reach
    // this line and both are turned away by the same fact about the row.
    $segment = Segment::factory()->create();
    Blast::factory()->aimedAtSegment($segment)->create();

    $owner = User::factory()->create();

    expect(Gate::forUser($owner)->allows('delete', $segment))->toBeTrue();

    $this->actingAs($owner)
        ->delete($this->campaignUrl("/segments/{$segment->getKey()}"))
        ->assertRedirect(route('segments.index'));

    expect(Segment::query()->count())->toBe(1);
});

test('a segment is removable again once the blast aimed at it has been re-aimed', function (): void {
    // The control, and it is what stops the refusal above being a segment that
    // can never be removed at all. It also names the way out an operator
    // actually has: re-aim the blast, then remove the segment.
    $segment = Segment::factory()->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
            'subject' => 'Aimed by postcode now',
            'body' => 'Re-aimed so the segment can go.',
            'postcode_prefixes' => '902',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl("/segments/{$segment->getKey()}"))
        ->assertRedirect(route('segments.index'));

    expect(Segment::query()->count())->toBe(0)
        // And the blast is still there, carrying the aim it was moved to. A
        // cascade would have taken it with the segment.
        ->and($blast->fresh()->postcode_prefixes)->toBe(['902']);
});

test('a sent blast still holds its segment, so the record cannot be tidied away', function (): void {
    // The direction that matters most and the one no later commit repairs. A
    // blast that has gone out is a record of what this campaign said to
    // people; removing the segment it names would leave the campaign unable to
    // say what it was aimed at, and `blast_recipients` answers a different
    // question -- who it reached, not who it was for.
    $segment = Segment::factory()->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->sent()->create();

    $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl("/segments/{$segment->getKey()}"))
        ->assertRedirect(route('segments.index'))
        // **The defect this closes, asserted rather than described.** The
        // refusal used to tell every operator to re-aim the blast first, and
        // BlastController::refuseCommitted() turns that away for exactly the
        // blast this branch exists to protect -- so the application instructed
        // somebody to do something it refuses. This case is permanent: no act
        // frees this segment, ever, which is why the sentence describes the
        // state instead of naming a remedy.
        ->assertInertiaFlash(
            'toast.message',
            'A blast the campaign has committed is aimed at that segment, '
            .'so it stays: it is the record of what that message was aimed at.',
        );

    expect(Segment::query()->count())->toBe(1)
        ->and($blast->fresh()->segment_id)->toBe($segment->getKey());
});

test('a draft alongside a committed blast does not soften what the operator is told', function (): void {
    // The mixed case, and it is the one that decides which count the message
    // reports. Re-aiming the draft is possible and frees nothing, because the
    // committed blast still holds the segment -- so a message naming two would
    // send an operator to move a blast that was never the obstacle, and then
    // leave them exactly where they started.
    $segment = Segment::factory()->create();
    Blast::factory()->aimedAtSegment($segment)->create();
    Blast::factory()->aimedAtSegment($segment)->sent()->create();

    $this->actingAs(User::factory()->create())
        ->delete($this->campaignUrl("/segments/{$segment->getKey()}"))
        ->assertRedirect(route('segments.index'))
        // Singular, and the count is one rather than two. Both halves can fail:
        // counting every aimed blast reads "2 blasts ... are aimed", and
        // dropping the committed branch reads the re-aim advice instead.
        ->assertInertiaFlash(
            'toast.message',
            'A blast the campaign has committed is aimed at that segment, '
            .'so it stays: it is the record of what that message was aimed at.',
        );

    expect(Segment::query()->count())->toBe(1);
});

test('the form for naming a segment offers the campaign\'s own seat and names the Congress', function (): void {
    // Offered, not followed (D-37): the form fills in the seat, and what is
    // stored is the district typed, so re-recording the seat later moves no
    // segment. The registry row is written here and undone in `finally`,
    // because the campaign harness re-reads it for every test and nothing rolls
    // a central write back.
    CampaignSeat::store($this->campaign, Seat::parse('MA-07', ZctaDistricts::shipped()));

    try {
        $this->actingAs(User::factory()->create())
            ->get($this->campaignUrl('/segments/create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('segments/Create')
                ->where('seat', 'MA-07')
                ->where('congress', '119th')
            );
    } finally {
        $this->campaign->setAttribute(CampaignSeat::KEY, null);
        $this->campaign->save();
    }

    // And a campaign with no seat recorded is offered none.
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/segments/create'))
        ->assertInertia(fn (Assert $page) => $page->where('seat', null));
});

test('an operator names a segment by district, and it is stored as the seat\'s own name', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'MA-07 supporters',
            'narrow_by' => 'district',
            'district' => ' ma-7 ',
            // Left over from the other field before the choice changed: the kind
            // is what `narrow_by` says, so this is not a second rule.
            'postcode_prefixes' => '021',
        ])
        ->assertRedirect(route('segments.index'));

    $segment = Segment::query()->sole();

    expect($segment->district)->toBe('MA-07')
        ->and($segment->postcode_prefixes)->toBeNull();
});

test('a district the map does not name is refused, and the refusal names the Congress', function (string $typed): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Nowhere',
            'narrow_by' => 'district',
            'district' => $typed,
        ])
        ->assertInvalid(['district' => "That is not a district in the 119th Congress's map. Write a state and a district number, like MA-07, or AL for a state's only seat, like AK-AL."]);

    expect(Segment::query()->count())->toBe(0);
})->with([
    // California has 52 seats and Alaska one, at large.
    'a seat past the last' => ['CA-53'],
    'a numbered seat in an at-large state' => ['AK-01'],
    'not a seat at all' => ['banana'],
]);

test('a segment naming a district has to name one', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Not yet aimed',
            'narrow_by' => 'district',
            'district' => '',
        ])
        ->assertInvalid(['district' => 'The district field is required.'])
        // The ZIP code field is not asked for when the segment narrows by
        // district.
        ->assertValid(['postcode_prefixes']);
});

test('an empty segment form is told once what it is missing, not twice', function (): void {
    // Recorded at Step 4 and settled here: the after-hook that refuses a line
    // of separators also ran when `required` had already failed, so one empty
    // field carried two messages. The page showed the first.
    //
    // Asked as JSON because that response lists every message a field
    // carries, where assertInvalid() checks that one message is among them and
    // would pass with the second one still beside it.
    $this->actingAs(User::factory()->create())
        ->postJson($this->campaignUrl('/segments'), [
            'name' => 'Not yet aimed',
            'postcode_prefixes' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.postcode_prefixes', ['The ZIP codes field is required.']);
});

test('a segment keeps the kind it was named with when it is re-aimed', function (): void {
    $operator = User::factory()->create();
    $district = Segment::factory()->inDistrict('MA-07')->create(['name' => 'By district']);
    $postcodes = Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'By ZIP code']);

    // A district segment re-aimed at another district; the ZIP codes posted
    // beside it are not a second rule.
    $this->actingAs($operator)
        ->patch($this->campaignUrl('/segments/'.$district->getKey()), [
            'name' => 'By district',
            'district' => 'MA-05',
            'postcode_prefixes' => '021',
        ])
        ->assertRedirect(route('segments.index'));

    // A ZIP code segment asked to become a district segment stays what it is:
    // a blast may be aimed at it, and a blast may not be aimed by district.
    $this->actingAs($operator)
        ->patch($this->campaignUrl('/segments/'.$postcodes->getKey()), [
            'name' => 'By ZIP code',
            'narrow_by' => 'district',
            'district' => 'MA-07',
            'postcode_prefixes' => '021',
        ])
        ->assertRedirect(route('segments.index'));

    expect($district->refresh()->district)->toBe('MA-05')
        ->and($district->postcode_prefixes)->toBeNull()
        ->and($postcodes->refresh()->postcode_prefixes)->toBe(['021'])
        ->and($postcodes->district)->toBeNull();
});

test('the edit form for a district segment is told which Congress it is read against', function (): void {
    $operator = User::factory()->create();
    $district = Segment::factory()->inDistrict('MA-07')->create();
    $postcodes = Segment::factory()->create();

    $this->actingAs($operator)
        ->get($this->campaignUrl('/segments/'.$district->getKey().'/edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('segments/Edit')
            ->where('segment.district', 'MA-07')
            ->where('congress', '119th')
        );

    // A segment of ZIP codes names no district, and is spared the relation.
    $this->actingAs($operator)
        ->get($this->campaignUrl('/segments/'.$postcodes->getKey().'/edit'))
        ->assertInertia(fn (Assert $page) => $page->where('congress', null));
});
