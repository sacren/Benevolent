<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Authorization\Permission;
use App\Blasts\BlastStatus;
use App\Blasts\SendBlast;
use App\Models\Blast;
use App\Models\BlastRecipient;
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
