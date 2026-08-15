<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Authorization\Permission;
use App\Blasts\BlastAudience;
use App\Blasts\BlastStatus;
use App\Blasts\SendBlast;
use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Segment;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

/**
 * Committing a blast to sending, through the route an operator actually uses.
 *
 * This file is about *authority and the transition*: who may commit a blast,
 * what the commit writes, and that a second one cannot happen. What the send
 * then does -- the messages, their envelopes, the recipient rows, and the two
 * campaigns not reaching each other -- is tests/Tenancy/CampaignBlastSendingTest,
 * because it needs a real worker and two campaigns rather than one request.
 */
/**
 * A subscribed supporter, so that a blast has somebody to reach.
 *
 * Named `subscribedSupporter` rather than `supporter` for the reason
 * tests/Pest.php already records against `supporterWithPostcode`: Pest loads
 * every test file into one process, so a second global function of the same
 * name is a fatal redeclare rather than a failing test.
 */
function subscribedSupporter(): Supporter
{
    return Supporter::factory()->create([
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
}

function owner(): User
{
    return User::factory()->create(['role' => OperatorRole::Owner]);
}

test('an owner commits a blast to sending, and the work is queued rather than done in the request', function (): void {
    // **Deliberately not Queue::fake().** Measured at Phase 1 Step 4: under the
    // fake a synchronous dispatch does not run the job either, so
    // assertPushed() passes identically whether the controller queues the work
    // or performs it inline -- L-26's blind spot arriving through a test helper.
    // The real database queue tells them apart, because queueing leaves a row
    // and running does not.
    config(['queue.default' => 'database']);

    $central = (string) config('tenancy.database.central_connection');
    DB::connection($central)->table('jobs')->delete();

    subscribedSupporter();
    $blast = Blast::factory()->create();
    $operator = owner();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    $queued = DB::connection($central)->table('jobs')->get();

    expect($queued)->toHaveCount(1)
        ->and(json_decode((string) $queued->first()->payload, true)['displayName'])
        ->toBe(SendBlast::class);

    // Nothing has been sent yet, which is the half that goes red on a
    // synchronous dispatch -- and the half that matters, because a send
    // performed inside the request would hold an operator's browser open for as
    // long as a list of any size took.
    expect(BlastRecipient::query()->count())->toBe(0);

    // The queue table is central, so it is outside this test's transaction and
    // would otherwise be left for the next test to find.
    DB::connection($central)->table('jobs')->delete();

    $reloaded = $blast->fresh();

    expect($reloaded->status)->toBe(BlastStatus::Queued)
        ->and($reloaded->queued_at)->not->toBeNull()
        // Who committed it, which the blast could not say before. `operator_id`
        // is authorship and is null here, because the factory wrote no author --
        // so this asserts the send recorded its own actor rather than reading
        // back something already present (D-17).
        ->and($reloaded->queued_by)->toBe($operator->getKey())
        ->and($reloaded->operator_id)->toBeNull();
});

test('a blast is committed by the request that won, and a second one is turned away', function (): void {
    Queue::fake();

    subscribedSupporter();
    $blast = Blast::factory()->create();
    $operator = owner();

    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    // The second attempt: a double-click, a refresh, a stale tab. It reaches the
    // same route with the same blast and must not produce a second message.
    $this->actingAs($operator)
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    // One dispatch, not two. The state transition names `draft` in its own
    // `where`, so the database decides which request committed the blast rather
    // than a read this second request could slip past.
    Queue::assertPushed(SendBlast::class, 1);

    expect($blast->fresh()->status)->toBe(BlastStatus::Queued);
});

test('an operator who may not send is refused, and nothing is queued', function (): void {
    Queue::fake();

    subscribedSupporter();
    $blast = Blast::factory()->create();

    // Staff hold EditBlasts and not SendBlasts, which is the only ability the
    // two roles disagree about. The first control in this application that
    // differs by role, and the first refusal that is about the act rather than
    // about the leverage it confers.
    $staff = User::factory()->create(['role' => OperatorRole::Staff]);

    $this->actingAs($staff)
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertForbidden();

    Queue::assertNothingPushed();

    // The refusal is not merely a status code: the blast is untouched, so a
    // policy that answered wrongly could not have half-committed it.
    expect($blast->fresh()->status)->toBe(BlastStatus::Draft)
        ->and($blast->fresh()->queued_at)->toBeNull()
        ->and($blast->fresh()->queued_by)->toBeNull();
});

test('an owner really may send, which is what makes the refusal above mean anything', function (): void {
    Queue::fake();

    subscribedSupporter();
    $blast = Blast::factory()->create();

    // The allow beside the deny, on the same ability through the same route
    // (L-19). A policy that refused everybody, or an authorization layer that
    // was never registered, satisfies the previous test perfectly.
    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    Queue::assertPushed(SendBlast::class, 1);
});

test('withdrawing the send permission is enough to refuse an owner', function (): void {
    Queue::fake();

    subscribedSupporter();
    $blast = Blast::factory()->create();

    // The configuration invariant behind the two tests above: the refusal comes
    // from the permission rather than from the role's name. Withdrawing the
    // permission for the length of this test refuses an Owner, which is what
    // proves BlastPolicy::send() reads Permission::SendBlasts and not
    // `$operator->role === Owner` -- the shortcut that would pass every other
    // assertion in this file.
    Gate::define(Permission::SendBlasts->value, fn (): bool => false);

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a blast the campaign has already committed cannot be sent again', function (): void {
    Queue::fake();

    subscribedSupporter();

    // Reachable through the factory rather than through a second request,
    // because the other three committed states are not reachable by any route
    // at all -- and all four must be refused, not only the one a double-click
    // produces.
    foreach ([BlastStatus::Queued, BlastStatus::Sending, BlastStatus::Sent, BlastStatus::Failed] as $status) {
        $blast = Blast::factory()->{match ($status) {
            BlastStatus::Queued => 'queued',
            BlastStatus::Sending => 'sending',
            BlastStatus::Sent => 'sent',
            BlastStatus::Failed => 'failed',
            default => 'queued',
        }}()->create();

        $this->actingAs(owner())
            ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
            ->assertRedirect(route('blasts.index'));

        // The status is untouched: a refusal that reset a sent blast to queued
        // would be worse than the double-send it was preventing.
        expect($blast->fresh()->status)->toBe($status);
    }

    Queue::assertNothingPushed();
});

test('a blast that currently reaches nobody is not spent', function (): void {
    Queue::fake();

    // No subscribed supporters at all. Committing is one-way, so an aim that
    // names nobody would burn a blast that can never be edited or sent again --
    // and the operator would have nothing to show for it.
    Supporter::factory()->create(['subscription_status' => SubscriptionStatus::Unsubscribed]);

    $blast = Blast::factory()->create();

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.edit', $blast));

    Queue::assertNothingPushed();

    // Still a draft, so the operator can re-aim it. This is the whole point of
    // the check: the blast survives its own refusal.
    expect($blast->fresh()->status)->toBe(BlastStatus::Draft)
        ->and($blast->fresh()->queued_at)->toBeNull();
});

test('a guest is sent to sign in rather than allowed to send', function (): void {
    Queue::fake();

    $blast = Blast::factory()->create();

    $this->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('login'));

    Queue::assertNothingPushed();
});

test('the edit page says whether a supporter could answer the message', function (): void {
    subscribedSupporter();
    $blast = Blast::factory()->create();

    // The campaign in this suite is provisioned without a contact address, so
    // this is the honest default rather than a contrived one: null means no
    // reply path, and the page that offers to send is where that has to be said.
    $this->actingAs(owner())
        ->get($this->campaignUrl('/blasts/'.$blast->getKey().'/edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('blasts/Edit')
            ->where('replyTo', null));
});

test('the list says what a send has done, counted from the record rather than the rule', function (): void {
    $sentBlast = Blast::factory()->sent()->create(['subject' => 'Went out']);
    $failedBlast = Blast::factory()->failed()->create([
        'subject' => 'Stopped early',
        'failure_reason' => 'The database refused the write.',
    ]);
    $queuedBlast = Blast::factory()->queued()->create(['subject' => 'Waiting']);

    BlastRecipient::factory()->count(3)->ofBlast($sentBlast)->sent()->create();
    BlastRecipient::factory()->ofBlast($sentBlast)->failed()->create();
    BlastRecipient::factory()->ofBlast($failedBlast)->sent()->create();

    $this->actingAs(owner())
        ->get($this->campaignUrl('/blasts'))
        ->assertOk()
        ->assertInertia(function ($page) use ($sentBlast, $failedBlast, $queuedBlast) {
            $counts = collect($page->toArray()['props']['blasts'])
                ->keyBy('id')
                ->map(fn (array $blast): array => [
                    'reached' => $blast['reached_count'],
                    'failed' => $blast['failed_count'],
                ]);

            // Counted from blast_recipients, so these are what happened rather
            // than what the audience rule predicts -- which is the distinction
            // D-14 accepted and the reason a per-blast count is honest where a
            // recomputed audience size would not be.
            expect($counts[$sentBlast->getKey()])->toBe(['reached' => 3, 'failed' => 1])
                ->and($counts[$failedBlast->getKey()])->toBe(['reached' => 1, 'failed' => 0])
                // A blast nothing has picked up reads zero rather than absent,
                // which is what lets the page say "waiting" rather than render
                // an empty cell that looks like a broken column.
                ->and($counts[$queuedBlast->getKey()])->toBe(['reached' => 0, 'failed' => 0]);

            return $page;
        });
});

test('the counts are two aggregates rather than a query for every blast', function (): void {
    foreach (range(1, 5) as $ignored) {
        $blast = Blast::factory()->sent()->create();
        BlastRecipient::factory()->ofBlast($blast)->sent()->create();
    }

    $operator = owner();

    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $this->actingAs($operator)->get($this->campaignUrl('/blasts'))->assertOk();

    $selects = collect(DB::connection('tenant')->getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'blast_recipients'))
        ->count();

    DB::connection('tenant')->disableQueryLog();

    // **The trigger Step 3 recorded against putting a count on this page was a
    // query per row, and this is the assertion that keeps the promise.** Both
    // counts are subselects on the one blasts query, so five blasts cost the
    // same as one -- where a recomputed audience size would have cost five
    // BlastAudience queries and would have been the wrong number besides.
    expect($selects)->toBe(1);
});

test('committing a segment-aimed blast freezes the rule it was aimed at', function (): void {
    // **D-27(a) at the moment it happens.** Until this step a blast pointed at
    // its segment for its whole life, so the campaign committing a message and
    // the rule that message would follow were two facts that could drift apart
    // between the commit and the worker. The commit now takes a copy.
    Queue::fake();

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();
    Supporter::factory()->create([
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    // A draft holds nothing frozen, which is what makes the assertion after the
    // commit a change rather than a restatement.
    expect($blast->committed_prefixes)->toBeNull();

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    $committed = $blast->fresh();

    expect($committed->status)->toBe(BlastStatus::Queued)
        ->and($committed->committed_prefixes)->toBe(['902'])
        // The pointer stays. The frozen rule is a record of what the aim said,
        // never a replacement for the aim -- a campaign still has to be able to
        // say which narrowing a message was sent to, and the foreign key that
        // stops that segment being deleted hangs off this column.
        ->and($committed->segment_id)->toBe($segment->getKey())
        // And the blast's own rule column is untouched, so D-26's shape is
        // exactly where Step 4 left it.
        ->and($committed->postcode_prefixes)->toBeNull();
});

test('editing the segment afterwards does not move what the committed blast holds', function (): void {
    // The property the freeze buys, asserted at the row rather than through a
    // send: the copy is a copy. The send half is BlastAudienceTest's, and the
    // whole-path half is the Tenancy file's.
    Queue::fake();

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create();
    Supporter::factory()->create([
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    // Somebody re-aims the segment. This is a legitimate act and stays one --
    // the alternative mechanism considered for D-27 was refusing it, which
    // would have locked this segment for as long as the blast sat queued, and
    // with no worker deployed anywhere that is forever.
    $segment->update(['postcode_prefixes' => ['90']]);

    expect($blast->fresh()->committed_prefixes)->toBe(['902'])
        // The segment really did move, so the assertion above is a difference
        // rather than two readings of the same unchanged row.
        ->and($segment->fresh()->postcode_prefixes)->toBe(['90']);
});

test('committing a blast that carries its own rule freezes nothing', function (): void {
    // The rows this must leave alone. A blast's own prefixes are already frozen
    // by being on its own row: nothing but its compose form can reach them, and
    // refuseCommitted() closes that form the moment it is committed. Freezing
    // them again would be a second copy of a rule that cannot move, which is
    // the duplication this phase exists to remove rather than add to.
    Queue::fake();

    Supporter::factory()->create([
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
    $blast = Blast::factory()->narrowedToPostcodes(['902'])->create();

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    $committed = $blast->fresh();

    expect($committed->status)->toBe(BlastStatus::Queued)
        ->and($committed->committed_prefixes)->toBeNull()
        ->and($committed->postcode_prefixes)->toBe(['902']);
});

test('committing a blast aimed at a district freezes the ZIP codes its seat claims', function (): void {
    // **The writing half of D-38.** A district segment's rule is not a literal
    // the campaign typed: `MA-07` names whatever relation the release in force
    // at send time ships, and with no worker running anywhere a committed blast
    // can wait across releases. So what the commit copies is the ZIP codes
    // themselves, and the column it copies them into is what tells the send to
    // replay them by the district's rule rather than the prefix matcher's.
    Queue::fake();

    Supporter::factory()->create([
        'postcode' => '02141',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);

    $segment = Segment::factory()->inDistrict('MA-07')->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    // A draft holds neither half frozen, which is what makes the assertions
    // after the commit a change rather than a restatement.
    expect($blast->committed_zip_codes)->toBeNull()
        ->and($blast->committed_prefixes)->toBeNull();

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    $committed = $blast->fresh();

    // The count is the relation's own, taken at Step 3 rather than from the
    // call that produced this list, and the two ends that matter are named:
    // 02141 lies wholly inside MA-07 and 02139 straddles its boundary.
    expect($committed->status)->toBe(BlastStatus::Queued)
        ->and($committed->committed_zip_codes)->toHaveCount(17)
        ->and($committed->committed_zip_codes)->toContain('02141')
        ->and($committed->committed_zip_codes)->not->toContain('02139')
        // The other half stays null, which is the constraint's "exactly one"
        // seen from the row: a blast frozen two ways would be one no reader
        // could replay without choosing.
        ->and($committed->committed_prefixes)->toBeNull()
        // The pointer stays, for the reason a prefix-aimed commit keeps it: a
        // campaign has to be able to say which narrowing a message was sent to.
        ->and($committed->segment_id)->toBe($segment->getKey());

    Queue::assertPushed(SendBlast::class);
});

test('a district blast holds what its seat claimed, not what the segment says afterwards', function (): void {
    // The property the freeze buys, asked of the half that moves for a reason
    // no operator can see: a district segment can be re-aimed at another seat,
    // and a release can redraw the seat it already names. The first is what
    // this drives, because it is the one a test can perform.
    Queue::fake();

    $frozenAudience = Supporter::factory()->create([
        'postcode' => '02141',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);
    Supporter::factory()->create([
        'postcode' => '90232',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);

    $segment = Segment::factory()->inDistrict('MA-07')->create();
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(owner())
        ->post($this->campaignUrl('/blasts/'.$blast->getKey().'/send'))
        ->assertRedirect(route('blasts.index'));

    $frozen = $blast->fresh()->committed_zip_codes;

    // Re-aimed at a seat on the other coast -- disjoint rather than wider, so
    // the assertion distinguishes "the frozen list was used" from "the edited
    // segment happened to name the same people". 90232 is claimed for CA-37.
    $segment->update(['district' => 'CA-37']);

    expect($blast->fresh()->committed_zip_codes)->toBe($frozen)
        ->and($blast->fresh()->committed_zip_codes)->toContain('02141')
        ->and($blast->fresh()->committed_zip_codes)->not->toContain('90232')
        // The segment really did move, so this is a difference rather than two
        // readings of an unchanged row.
        ->and($segment->fresh()->district)->toBe('CA-37')
        // And the audience the send will walk is still the one committed to.
        ->and(BlastAudience::for($blast->fresh())->pluck('id')->all())
        ->toBe([$frozenAudience->getKey()]);
});
