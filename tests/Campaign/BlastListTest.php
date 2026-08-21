<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Blasts\BlastStatus;
use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Segment;
use App\Models\Unsubscribe;
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
    Blast::factory()->narrowedToPostcodes(['902', '6060'])->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            // Null would be a blast aimed at everybody, so the prefixes have to
            // arrive as the list they are rather than as a rendered string the
            // server decided on: the page says what null means in words, and it
            // cannot do that if it is handed words either way.
            ->where('blasts.0.postcode_prefixes', ['902', '6060'])
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
    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Beverly Hills']);

    Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.segment_id', $segment->getKey())
            ->where('blasts.0.segment.name', 'Beverly Hills')
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
    $first = Segment::factory()->create(['name' => 'Pasadena']);
    $second = Segment::factory()->create(['name' => 'Beverly Hills']);

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

test('the page carries what a committed blast froze, alongside the segment as it stands now', function (): void {
    // **The reporting half of D-27, and the page cannot be honest without both
    // halves.** A segment stays editable after a blast has gone out, so the
    // eager-loaded segment is today's name and today's rule. Describing a sent
    // blast from it would report the wrong narrowing as the one that went out.
    // The frozen rule is what it actually reached, so the server has to send
    // it rather than leaving the page to infer the aim from a live row.
    $segment = Segment::factory()->narrowedToPostcodes(['911'])->create(['name' => 'Beverly Hills']);

    $blast = Blast::factory()->aimedAtSegment($segment)->sent()->create();

    // The narrowing moves after the send, in both of the ways it can: renamed,
    // and re-aimed somewhere disjoint.
    $segment->update(['name' => 'Cambridge', 'postcode_prefixes' => ['902']]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.committed_prefixes', ['911'])
            // The pointer and the segment still arrive, because "which
            // narrowing did we use" is a question the campaign still asks and
            // the answer is still on the row. What the page must not do is
            // treat them as the audience.
            ->where('blasts.0.segment_id', $segment->getKey())
            ->where('blasts.0.segment.name', 'Cambridge')
            ->where('blasts.0.segment.postcode_prefixes', ['902'])
        );

    expect($blast->fresh()->committed_prefixes)->toBe(['911']);
});

test('a draft carries no frozen rule to the page, so the list keeps naming its segment', function (): void {
    // The control. Without it the assertions above are satisfied by a server
    // that sends a frozen rule for everything, which would make the list stop
    // following a pointer that is still live -- the opposite defect, and the one
    // that would quietly undo what pointing at a segment is for.
    $segment = Segment::factory()->narrowedToPostcodes(['911'])->create(['name' => 'Beverly Hills']);

    Blast::factory()->aimedAtSegment($segment)->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.committed_prefixes', null)
            ->where('blasts.0.segment.name', 'Beverly Hills')
        );
});

test('the list says how many people left because of each message', function (): void {
    // The module's whole subject, on the surface that shows a campaign its
    // messages. Two of the three copies were followed by a withdrawal through
    // that copy's own link, which is the only thing that can credit a blast.
    $blast = Blast::factory()->sent()->create(['subject' => 'Object before Friday']);

    $copies = BlastRecipient::factory()->count(3)->ofBlast($blast)->sent()->create();

    Unsubscribe::create(['blast_recipient_id' => $copies[0]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => $copies[1]->getKey()]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.withdrawn_count', 2)
            // And the basis the count is drawn from: all three copies carried a
            // link, so the two is the whole answer rather than a floor.
            ->where('blasts.0.attributable_count', 3)
            ->where('blasts.0.reached_count', 3)
        );
});

test('a blast whose copies carried no link reports no basis, which is not the same as no withdrawals', function (): void {
    // **The state §7 criterion 3 exists for.** These copies were claimed before
    // messages carried their own link, so nobody who left through one of them
    // could ever have been counted here. The page must be able to tell that
    // from a message nobody left over, and it cannot unless the server sends
    // the basis as well as the count.
    $blast = Blast::factory()->sent()->create(['subject' => 'Sent before any of this']);

    BlastRecipient::factory()->count(3)->ofBlast($blast)->sent()->withoutLinkToken()->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.attributable_count', 0)
            ->where('blasts.0.withdrawn_count', 0)
            // The send itself is untouched and still says what it did. Without
            // this the assertions above are satisfied by a blast that reached
            // nobody, which is a different fact entirely.
            ->where('blasts.0.reached_count', 3)
        );
});

test('a blast whose copies are only partly attributable says how many could speak', function (): void {
    // **Reachable through SendBlast's resumption**, which is why it is guarded
    // rather than dismissed: a send interrupted across the migration that added
    // `link_token` claims the rest of its recipients afterwards, so one blast
    // holds copies of both kinds. A surface reading this as a simple yes or no
    // would report two of four as the whole answer.
    $blast = Blast::factory()->sent()->create(['subject' => 'Interrupted and resumed']);

    $carrying = BlastRecipient::factory()->count(2)->ofBlast($blast)->sent()->create();
    BlastRecipient::factory()->count(2)->ofBlast($blast)->sent()->withoutLinkToken()->create();

    Unsubscribe::create(['blast_recipient_id' => $carrying[0]->getKey()]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.reached_count', 4)
            // Two of the four copies could name themselves, so one withdrawal
            // is one out of two rather than one out of four.
            ->where('blasts.0.attributable_count', 2)
            ->where('blasts.0.withdrawn_count', 1)
        );
});

test('a link minted for a message that never went is not counted as a basis', function (): void {
    // **The fifth state Step 3 recorded, and it is not an outcome.** A claim
    // carries a token from the moment it is written, before the message is
    // handed to the mailer -- so a copy that failed holds a link that reached
    // nobody. Counting it would make the basis a promise about inboxes the
    // campaign never got to.
    $blast = Blast::factory()->failed()->create(['subject' => 'Stopped early']);

    BlastRecipient::factory()->ofBlast($blast)->sent()->create();
    BlastRecipient::factory()->count(2)->ofBlast($blast)->failed()->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.reached_count', 1)
            ->where('blasts.0.failed_count', 2)
            // One, not three: the two failed copies hold tokens the database
            // minted and no message ever carried anywhere.
            ->where('blasts.0.attributable_count', 1)
        );
});

test('one copy followed by two withdrawals counts twice without inflating what the send reached', function (): void {
    // **Two assertions with different standing, and saying which is which is
    // the point of this comment.** D-47 measured that one copy can be followed
    // by more than one real withdrawal: somebody leaves, an operator puts them
    // back, they leave again.
    //
    // The `withdrawn_count` half is a live guard. It goes red against a count
    // of the copies that were left rather than of the acts of leaving --
    // `count(distinct blast_recipient_id)` reports 1 where 2 is the truth,
    // confirmed by running it.
    //
    // **The `reached_count` half is a tripwire for a shape this page does not
    // currently have, and no mutation available today can reach it.** Each
    // aggregate here is its own correlated subquery, so `unsubscribes` cannot
    // touch the reach count whatever is done to the withdrawal count. It is
    // written for the grouped single-pass shape, where the two counts share one
    // GROUP BY and a doubled withdrawal multiplies the recipient row: measured
    // on 4,000 supporters, that shape reported 3,981 reached where 3,980 was
    // the truth. Said here rather than left for a reader to assume it was
    // measured (Blueprint v0.27).
    //
    // **Step 5 weighed that shape and did not take it**, so this assertion
    // stays unreachable rather than becoming live as Step 4 expected. It is
    // kept for the same reason it was written: the shape is the recorded
    // remedy for the day this page's trigger fires, and the day somebody
    // reaches for it is the day the hazard arrives. Re-measured there on ten
    // blasts of 100,000 copies, the folded form reported 99,501 reached where
    // 99,500 was true -- the same defect at the larger size.
    $blast = Blast::factory()->sent()->create(['subject' => 'Left twice']);

    $copy = BlastRecipient::factory()->ofBlast($blast)->sent()->create();

    Unsubscribe::create(['blast_recipient_id' => $copy->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => $copy->getKey()]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blasts.0.withdrawn_count', 2)
            ->where('blasts.0.reached_count', 1)
            ->where('blasts.0.attributable_count', 1)
        );
});

test('a withdrawal that names no message is counted for the campaign and against no blast', function (): void {
    // **Where an unattributed row lives, which is the question it forces.** It
    // points at no copy, so there is no row on this page it belongs to, and
    // adding it to one would be exactly the misattribution D-46 exists to
    // prevent. It belongs to the campaign, so the campaign is what carries it.
    $blast = Blast::factory()->sent()->create(['subject' => 'Object before Friday']);

    $copy = BlastRecipient::factory()->ofBlast($blast)->sent()->create();

    // **An attributed withdrawal sits beside them deliberately**, and without it
    // this test cannot fail for the reason it names: with only unattributed
    // rows in the table, "the withdrawals that name no message" and "every
    // withdrawal" are the same number, and a figure counting the whole table
    // passes exactly as the right one does. Measured -- the first version of
    // this test stayed green against precisely that defect.
    Unsubscribe::create(['blast_recipient_id' => $copy->getKey()]);

    // Two people left through links mailed before messages carried their own,
    // which keep working and can name only a person.
    Unsubscribe::create(['blast_recipient_id' => null]);
    Unsubscribe::create(['blast_recipient_id' => null]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page
            // Two of the three, so a figure reading the whole table says 3.
            ->where('unattributedWithdrawals', 2)
            // And the blast is credited with the one that named its copy, and
            // with neither of the two that named none.
            ->where('blasts.0.withdrawn_count', 1)
        );
});

test('a campaign with no withdrawals at all carries a zero rather than nothing', function (): void {
    // The control for the assertion above. Without it, a server that never sent
    // the campaign-level figure would satisfy every other test here, and the
    // page would have to decide what an absent prop meant.
    Blast::factory()->sent()->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertInertia(fn (Assert $page) => $page->where('unattributedWithdrawals', 0));
});

test('the withdrawal count does not add a query per blast', function (): void {
    // The sibling of the segment guard above, on the aggregate this step added.
    // It is the cheap protection against somebody later "fixing" this into a
    // per-row lookup, which is the shape the page already refuses for an
    // audience count and which no assertion about values would notice.
    $blasts = Blast::factory()->count(3)->sent()->create();

    foreach ($blasts as $blast) {
        $copy = BlastRecipient::factory()->ofBlast($blast)->sent()->create();
        Unsubscribe::create(['blast_recipient_id' => $copy->getKey()]);
    }

    $unsubscribeQueries = 0;

    DB::listen(function ($query) use (&$unsubscribeQueries): void {
        if (str_contains($query->sql, '"unsubscribes"')) {
            $unsubscribeQueries++;
        }
    });

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/blasts'))
        ->assertOk();

    // Two for three blasts: the list's own statement, which carries the
    // per-blast count as a subselect, and the campaign-level count beside it.
    // Written as an exact number rather than "fewer than four", because the
    // claim is that neither grows with the list.
    expect($unsubscribeQueries)->toBe(2);
});
