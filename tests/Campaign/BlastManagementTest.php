<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Blasts\BlastStatus;
use App\Models\Blast;
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
            'postcode_prefixes' => 'M15, EH8',
        ])
        ->assertRedirect();

    $blast = Blast::query()->sole();

    expect($blast->subject)->toBe('Object before Friday')
        ->and($blast->body)->toBe('The consultation closes at five.')
        ->and($blast->postcode_prefixes)->toBe(['M15', 'EH8'])
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

test('the model permits exactly the three columns a compose form fills', function (): void {
    // L-14's pairing: the behavioural assertion above, and the configuration
    // invariant behind it, in the same run so neither half can stand alone.
    //
    // This one is not decorative even though widening it changes no behaviour
    // today. Laravel guards every attribute by default, so this list is what
    // *permits* the three that a compose form fills -- and it is the thing a
    // later action passing raw input to create() or update() would be relying
    // on without knowing it.
    expect((new Blast)->getFillable())->toBe(['subject', 'body', 'postcode_prefixes']);
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
            'postcode_prefixes' => 'M15, , EH8,',
        ]);

    expect(Blast::query()->sole()->postcode_prefixes)->toBe(['M15', 'EH8']);
});

test('the edit page shows the draft and how many supporters it currently matches', function (): void {
    Supporter::factory()->create(['postcode' => 'M15 6BH']);
    Supporter::factory()->create(['postcode' => 'm156bh']);
    Supporter::factory()->create(['postcode' => 'EH8 9YL']);
    Supporter::factory()->create([
        'postcode' => 'M15 9AA',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    $blast = Blast::factory()->narrowedToPostcodes(['M15'])->create();

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
    Supporter::factory()->create(['postcode' => 'M15 6BH']);
    Supporter::factory()->create(['postcode' => 'EH8 9YL']);

    $blast = Blast::factory()->narrowedToPostcodes(['M15'])->create();

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/blasts/'.$blast->getKey()), [
            'subject' => 'Now aimed at Edinburgh',
            'body' => 'Rewritten.',
            'postcode_prefixes' => 'eh8',
        ])
        // Back to the same page, so the count is recomputed against the aim
        // just saved rather than the one it replaced.
        ->assertRedirect(route('blasts.edit', $blast));

    expect($blast->fresh()->postcode_prefixes)->toBe(['eh8']);

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
