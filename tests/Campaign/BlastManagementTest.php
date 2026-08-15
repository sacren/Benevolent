<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Blasts\BlastStatus;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Writing a blast and aiming it, the way an operator does it: over HTTP, on the
 * campaign's own hostname, signed in.
 *
 * tests/Campaign/BlastAudienceTest.php asks who a blast would reach.
 * tests/Campaign/BlastListTest.php asks whether the list page answers. This
 * file asks the questions in between -- whether the pages consult the policy,
 * what a form is allowed to set, and whether a blast the campaign has already
 * committed can still be changed.
 */

test('an operator can reach the form for writing a blast', function (): void {
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('blasts/Create'));
});

test('writing a blast stores it as a draft and records who wrote it', function (): void {
    $operator = User::factory()->create();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Object before Friday',
            'body' => 'The consultation closes at five.',
            'postcode_prefixes' => '902, 021',
        ])
        ->assertRedirect();

    $blast = Blast::query()->sole();

    expect($blast->subject)->toBe('Object before Friday')
        ->and($blast->body)->toBe('The consultation closes at five.')
        ->and($blast->postcode_prefixes)->toBe(['902', '021'])
        // A blast arrives in the only state it can be edited or sent from, and
        // the timestamp that would say otherwise is absent -- the pairing the
        // table's check constraint holds.
        ->and($blast->status)->toBe(BlastStatus::Draft)
        ->and($blast->queued_at)->toBeNull()
        ->and($blast->operator_id)->toBe($operator->getKey());
});

test('a form cannot set the state, the moment of committing, or who wrote it', function (): void {
    // Every field below is one this application writes and no operator submits:
    // a form able to set them could mark a message sent that never went, return
    // a committed blast to draft, or put somebody else's name on a message that
    // went out.
    //
    // **What this guards is ComposeBlastRequest::composed(), not the model's
    // fillable list**, and the difference was measured rather than assumed:
    // widening the fillable list to include status, queued_at and finished_at
    // reddens nothing at all, because composed() returns three keys and the
    // controller assigns nothing else, so the extra columns are never offered.
    // The fillable list is pinned directly by the test below, where it can
    // actually fail.
    $operator = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Trying it on',
            'body' => 'Should still be a draft.',
            'status' => BlastStatus::Sent->value,
            'queued_at' => now()->toDateTimeString(),
            'finished_at' => now()->toDateTimeString(),
            'operator_id' => $someoneElse->getKey(),
        ])
        ->assertRedirect();

    $blast = Blast::query()->sole();

    expect($blast->status)->toBe(BlastStatus::Draft)
        ->and($blast->queued_at)->toBeNull()
        ->and($blast->finished_at)->toBeNull()
        ->and($blast->operator_id)->toBe($operator->getKey());
});

test('the model permits exactly the four columns a compose form fills', function (): void {
    // L-14's pairing: the behavioural assertion above, and the configuration
    // invariant behind it, in the same run so neither half can stand alone.
    //
    // This one is not decorative. Laravel guards every attribute by default, so
    // this list is what *permits* the four that a compose form fills -- and
    // `segment_id` is the one that proves it, because mass assignment drops a
    // guarded attribute silently: removing that name would leave every blast
    // aimed at a segment aimed at nobody in particular, with no error anywhere.
    // That is the opposite of how this list has failed twice before, where a
    // request object's own allowlist made widening it harmless.
    expect((new Blast)->getFillable())->toBe(['subject', 'body', 'segment_id', 'postcode_prefixes']);
});

test('a blast needs something to say', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), ['subject' => '', 'body' => ''])
        ->assertSessionHasErrors(['subject', 'body']);

    expect(Blast::query()->count())->toBe(0);
});

test('naming no postcodes stores null, which is the whole list rather than none of it', function (): void {
    // The distinction BlastAudience turns on, made at the point the value is
    // written. An empty field means "everyone"; storing an empty list instead
    // would store an aim that names nothing, which reaches nobody.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'To everyone',
            'body' => 'No narrowing.',
            'postcode_prefixes' => '   ',
        ]);

    expect(Blast::query()->sole()->postcode_prefixes)->toBeNull();
});

test('a postcode field holding nothing but separators stores null too', function (): void {
    // Reaches a branch the test above cannot, which was found by breaking the
    // parser and watching nothing go red. Laravel trims every string input and
    // converts an empty result to null, so `'   '` never arrives as a string at
    // all: the early return handles it and the parsing is never entered. A
    // field of separators *does* arrive as a string, parses to an empty list,
    // and is therefore the only input that proves an empty list is stored as
    // null rather than as an aim that names nothing -- which BlastAudience
    // answers with nobody.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'To everyone',
            'body' => 'No narrowing.',
            'postcode_prefixes' => ' , , ',
        ]);

    expect(Blast::query()->sole()->postcode_prefixes)->toBeNull();
});

test('blank entries between real postcodes are dropped', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Two areas',
            'body' => 'Narrowed.',
            'postcode_prefixes' => '902, , 021,',
        ]);

    expect(Blast::query()->sole()->postcode_prefixes)->toBe(['902', '021']);
});

test('the edit page shows the draft and how many supporters it currently matches', function (): void {
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '90210 1234']);
    Supporter::factory()->create(['postcode' => '02139']);
    Supporter::factory()->create([
        'postcode' => '90211',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    $blast = Blast::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('blasts/Edit')
            ->where('blast.id', $blast->getKey())
            // Two spellings of one postcode area, and not the unsubscribed
            // supporter in the same area or the one in another.
            ->where('audienceSize', 2)
        );
});

test('changing a draft re-aims it, and the count follows the new aim', function (): void {
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '02139']);

    $blast = Blast::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
            'subject' => 'Now aimed at Edinburgh',
            'body' => 'Rewritten.',
            'postcode_prefixes' => '021',
        ])
        // Back to the same page, so the count is recomputed against the aim
        // just saved rather than the one it replaced.
        ->assertRedirect(route('blasts.edit', $blast));

    expect($blast->fresh()->postcode_prefixes)->toBe(['021']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertInertia(fn (Assert $page) => $page->where('audienceSize', 1));
});

test('a blast the campaign has committed can no longer be changed', function (): void {
    // The state guard, and it is deliberately not the policy's: BlastPolicy
    // answers authority and never state, so an operator who may edit blasts is
    // told the blast has gone rather than told they may not. A 403 here would
    // give an Owner the one diagnosis that is untrue.
    //
    // Every committed state, not one taken as representative, because the
    // question is "has this left draft" and a guard written against a single
    // case is one new case away from being wrong.
    $operator = User::factory()->owner()->create();

    foreach (['queued', 'sending', 'sent', 'failed'] as $state) {
        $blast = Blast::factory()->{$state}()->create(['subject' => 'Already gone']);

        $this->actingAs($operator)
            ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
            ->assertRedirect(route('blasts.index'));

        $this->actingAs($operator)
            ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
                'subject' => 'Rewritten after the fact',
                'body' => 'Should not land.',
            ])
            ->assertRedirect(route('blasts.index'));

        // The refusal is the claim rather than the redirect: nothing was
        // written.
        expect($blast->fresh()->subject)->toBe('Already gone');
    }
});

test('a draft is still editable, which is what makes the refusal above mean something', function (): void {
    // The positive half of the pair, in the same file and through the same
    // call. Without it, a controller that turned away every edit would satisfy
    // the test above exactly as the real guard does.
    $blast = Blast::factory()->create(['subject' => 'Still a draft']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
            'subject' => 'Edited',
            'body' => 'Landed.',
        ]);

    expect($blast->fresh()->subject)->toBe('Edited');
});

test('every compose route refuses an operator who has lost the grant', function (): void {
    // All four listed rather than one taken as representative, because
    // authorize() is a separate line in each action and dropping any one of
    // them is a separate defect -- measured at Phase 1 Step 4, where removing
    // it from a single action reddened nothing at all.
    //
    // Both roles hold EditBlasts today, so the refusal has to be built rather
    // than found: the grant is withdrawn for the length of this test.
    $operator = User::factory()->create();
    $blast = Blast::factory()->create(['subject' => 'Untouched']);

    Gate::define(Permission::EditBlasts->value, fn (): bool => false);

    $this->actingAs($operator)
        ->get($this->campaignUrl('/blasts/create'))
        ->assertForbidden();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts'), ['subject' => 'No', 'body' => 'No'])
        ->assertForbidden();

    $this->actingAs($operator)
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertForbidden();

    $this->actingAs($operator)
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), ['subject' => 'No', 'body' => 'No'])
        ->assertForbidden();

    // Nothing got through, which is the claim rather than the status codes.
    expect(Blast::query()->count())->toBe(1)
        ->and($blast->fresh()->subject)->toBe('Untouched');
});

test('a guest is sent to sign in rather than shown the compose form', function (): void {
    $blast = Blast::factory()->create();

    $this->get($this->campaignUrl('/blasts/create'))
        ->assertRedirect(route('login'));

    $this->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertRedirect(route('login'));
});

test('the compose form is handed the campaign\'s own segments to aim at', function (): void {
    Segment::factory()->create(['name' => 'Beverly Hills']);
    Segment::factory()->create(['name' => 'Pasadena']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('blasts/Create')
            // Ordered by name, matching the segment list an operator has just
            // been reading: the same segments in a second order would look like
            // different segments.
            ->where('segments.0.name', 'Beverly Hills')
            ->where('segments.1.name', 'Pasadena')
            ->count('segments', 2)
        );
});

test('a blast can be aimed at a segment from the form', function (): void {
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '02139']);

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed by name',
            'body' => 'Pointing rather than retyping.',
            'segment_id' => (string) $segment->getKey(),
        ])
        ->assertRedirect();

    $blast = Blast::query()->sole();

    // The pointer is stored and the blast carries no rule of its own, which is
    // the pair the check constraint holds and the form has to produce.
    expect($blast->segment_id)->toBe($segment->getKey())
        ->and($blast->postcode_prefixes)->toBeNull();

    // And the edit page counts against the segment's rule rather than against
    // nothing, which is what proves the aim survived the round trip as an aim
    // and not merely as a column.
    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blast.segment_id', $segment->getKey())
            ->where('audienceSize', 1)
        );
});

test('a form cannot aim a blast two ways at once', function (): void {
    $segment = Segment::factory()->create();

    // The database refuses this row with SQLSTATE 23514 and the operator would
    // see a 500. The rule turns that into a sentence about which of the two
    // they have to give up -- the same division of labour UniqueSupporterEmail
    // makes beside the lower(email) index.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed two ways',
            'body' => 'Refused.',
            'segment_id' => (string) $segment->getKey(),
            'postcode_prefixes' => '902',
        ])
        ->assertInvalid(['segment_id' => 'A blast is aimed one way. Choose a segment or type ZIP codes, not both.']);

    expect(Blast::query()->count())->toBe(0);
});

test('a stray separator in the postcode field does not refuse a segment', function (): void {
    // The rule asks prefixes() rather than the raw field, so the form's notion
    // of "the operator typed postcodes" is the same one the storage uses. A
    // rule written against the raw string would refuse this on a comma.
    $segment = Segment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed by name, with a leftover comma',
            'body' => 'Accepted.',
            'segment_id' => (string) $segment->getKey(),
            'postcode_prefixes' => ' , ,',
        ])
        ->assertSessionHasNoErrors();

    expect(Blast::query()->sole()->segment_id)->toBe($segment->getKey());
});

test('a blast cannot be aimed at a segment that does not exist here', function (): void {
    // Ids restart at 1 in every campaign, so an id naming another campaign's
    // segment is a plausible value rather than an obvious forgery. The rule
    // runs on the campaign's own connection, so it simply is not there.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed somewhere else',
            'body' => 'Refused.',
            'segment_id' => '1',
        ])
        ->assertSessionHasErrors('segment_id');

    expect(Blast::query()->count())->toBe(0);
});

test('re-aiming a draft from a segment to postcodes clears the pointer, and back again', function (): void {
    Supporter::factory()->create(['postcode' => '90210']);
    Supporter::factory()->create(['postcode' => '02139']);

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    // **Both directions, because only one of them can fail quietly.** Switching
    // away from a segment has to null the pointer, or the saved row names two
    // aims and the database refuses it; switching back has to null the
    // prefixes for the same reason. composed() returns both halves every time
    // for exactly this, and a form that returned only the field it was given
    // would produce the refused row on the first switch.
    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
            'subject' => 'Now aimed by postcode',
            'body' => 'Rewritten.',
            'postcode_prefixes' => '021',
        ])
        ->assertSessionHasNoErrors();

    $blast->refresh();

    expect($blast->segment_id)->toBeNull()
        ->and($blast->postcode_prefixes)->toBe(['021']);

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
            'subject' => 'Back to the segment',
            'body' => 'Rewritten again.',
            'segment_id' => (string) $segment->getKey(),
        ])
        ->assertSessionHasNoErrors();

    $blast->refresh();

    expect($blast->segment_id)->toBe($segment->getKey())
        ->and($blast->postcode_prefixes)->toBeNull();
});

test('an operator who may not read segments still gets the compose page, without them', function (): void {
    // **The proportional answer, guarded, because the heavy-handed one was
    // tried first and reddened nothing.** A hard authorize on the segment
    // policy here would 403 the whole page -- and an operator holding
    // EditBlasts without ViewSupporters plainly may write a blast and aim it by
    // postcode. So the page renders and the select is simply not offered, which
    // is what it already does for a campaign that has named none.
    //
    // Both roles hold ViewSupporters today, so the refusal has to be built
    // rather than found: the grant is withdrawn for the length of this test,
    // the same way the compose-route test above withdraws EditBlasts.
    Segment::factory()->create(['name' => 'Beverly Hills']);

    $operator = User::factory()->create();

    Gate::define(Permission::ViewSupporters->value, fn (): bool => false);

    $this->actingAs($operator)
        ->get($this->campaignUrl('/blasts/create'))
        // Not forbidden. The authority to be here is EditBlasts, and it is
        // untouched.
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('blasts/Create')
            ->count('segments', 0)
        );

    $blast = Blast::factory()->create();

    $this->actingAs($operator)
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->count('segments', 0));
});

test('the compose form is not handed a segment that narrows by district', function (): void {
    // A blast may not be aimed by district until the statement that commits one
    // writes the frozen ZIP codes (D-38), so the select offers only segments of
    // ZIP code prefixes -- and still offers those.
    Segment::factory()->create(['name' => 'Beverly Hills']);
    Segment::factory()->inDistrict('MA-07')->create(['name' => 'MA-07 supporters']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts/create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('segments.0.name', 'Beverly Hills')
            ->count('segments', 1)
        );
});

test('a blast cannot be aimed at a segment that narrows by district, and is told why', function (): void {
    // Posted around the page, which does not offer the segment. The refusal
    // names the aim as the reason, which nothing else would: the audience now
    // resolves such a segment live, so the draft would look sendable right up
    // to the statement that commits it, where the freeze it owes is not yet
    // written.
    $district = Segment::factory()->inDistrict('MA-07')->create();
    $postcodes = Segment::factory()->create();
    $operator = User::factory()->create();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed by district',
            'body' => 'Refused.',
            'segment_id' => (string) $district->getKey(),
        ])
        ->assertInvalid(['segment_id' => 'That segment narrows by congressional district, and a blast cannot be aimed by district yet.']);

    expect(Blast::query()->count())->toBe(0);

    // The positive half through the same route in the same run.
    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed by ZIP code',
            'body' => 'Stored.',
            'segment_id' => (string) $postcodes->getKey(),
        ])
        ->assertSessionHasNoErrors();

    expect(Blast::query()->sole()->segment_id)->toBe($postcodes->getKey());
});
