<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\Gate;

/*
 * The campaign's supporter list, reached the way an operator reaches it: over
 * HTTP, on the campaign's own hostname, signed in as one of its operators.
 *
 * Separate from SupporterAuthorizationTest, which asks who *may* do what and
 * answers through the gate and the policy directly. This file asks whether the
 * page an operator actually loads asks that question at all, and what it hands
 * the browser once it has.
 */

test('an operator sees the campaign supporters', function (): void {
    // The positive half, and the one that fails if the page stops asking the
    // policy, stops querying, or is handed the wrong campaign's rows. Every
    // negative claim below is evidence only because this one shows the same
    // request answering differently.
    $subscribed = Supporter::factory()->create(['email' => 'listed@example.test']);
    $unsubscribed = Supporter::factory()->unsubscribed()->create(['email' => 'quiet@example.test']);

    // Asserted without reference to position, so that a change of sort order
    // reddens the ordering test below and this one alone keeps naming its own
    // cause. Written positionally it went red on a dropped tiebreak and
    // reported "an operator sees the campaign supporters", which is not what
    // had broken.
    $response = $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('supporters/Index')
            ->has('supporters.data', 2)
        );

    $sent = collect($response->viewData('page')['props']['supporters']['data'])
        ->keyBy('email');

    // Both statuses are sent. An unsubscribed supporter stays on the list --
    // that is the whole reason the status exists rather than a deletion -- so a
    // page that quietly filtered them would lose the record that keeps a later
    // import from putting them back.
    expect($sent->keys()->sort()->values()->all())
        ->toBe([$subscribed->email, $unsubscribed->email])
        ->and($sent[$subscribed->email]['subscription_status'])
        ->toBe(SubscriptionStatus::Subscribed->value)
        ->and($sent[$unsubscribed->email]['subscription_status'])
        ->toBe(SubscriptionStatus::Unsubscribed->value);
});

test('the list arrives newest first, with ties broken so the order is total', function (): void {
    // Two supporters sharing a created_at is not a contrived case: an import
    // writes a whole file within one second. Without the id tiebreak the
    // database is free to return them in either order, so a list that looked
    // stable would reshuffle between requests and no test would ever say why.
    $arrived = now()->subDay();

    $first = Supporter::factory()->create(['email' => 'first@example.test', 'created_at' => $arrived]);
    $second = Supporter::factory()->create(['email' => 'second@example.test', 'created_at' => $arrived]);
    $newer = Supporter::factory()->create(['email' => 'newer@example.test', 'created_at' => now()]);

    expect($second->getKey())->toBeGreaterThan($first->getKey());

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('supporters.data.0.email', $newer->email)
            ->where('supporters.data.1.email', $second->email)
            ->where('supporters.data.2.email', $first->email)
        );
});

test('a supporter with no name at all is still on the list', function (): void {
    // The row a petition widget produces. It is ordinary rather than broken --
    // the person is perfectly contactable -- so the page must carry it rather
    // than drop it, and the null must survive the trip so the browser can say
    // "no name recorded" instead of rendering an empty cell.
    $nameless = Supporter::factory()->withoutName()->create(['email' => 'nameless@example.test']);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('supporters.data', 1)
            ->where('supporters.data.0.email', $nameless->email)
            ->where('supporters.data.0.name', null)
            ->where('supporters.data.0.given_name', null)
            ->where('supporters.data.0.family_name', null)
        );
});

test('a guest is sent to sign in rather than shown the list', function (): void {
    Supporter::factory()->create();

    // route() rather than campaignUrl(): tenancy is initialized, so the
    // generator already produces the campaign's own host -- and it includes the
    // port, which campaignUrl() does not, so building the expectation by hand
    // would fail on the port rather than on the redirect.
    $this->get($this->campaignUrl('/supporters'))
        ->assertRedirect(route('login'));
});

test('the page asks the policy, and refuses an operator the policy refuses', function (): void {
    // The deny half of the pair at the top of this file, and it cannot stand
    // alone: a page that threw a 403 at everybody, or one whose route did not
    // exist, would satisfy this assertion exactly as a working guard does. What
    // makes it evidence is the first test in this file, where the identical
    // request succeeds for an operator who holds the permission.
    //
    // Staff hold ViewSupporters today, so the refusal has to be built rather
    // than found: the grant is withdrawn from the role for the length of this
    // test. That is deliberately the *permission* being withdrawn rather than
    // the policy being stubbed, because it is the shape of the real change --
    // a role losing a grant -- and it proves the controller consults the policy
    // rather than waving every signed-in operator through.
    $operator = User::factory()->create();

    Gate::define(Permission::ViewSupporters->value, fn (): bool => false);

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters'))
        ->assertForbidden();
});

test('the list arrives one page at a time, and the count is of the whole list', function (): void {
    // Sixty supporters against a page of fifty, so the page is genuinely
    // partial. The two numbers this asserts are the ones a paginated page makes
    // easy to confuse: `data` is what this request carries and `total` is what
    // the campaign has, and the obvious implementation counts the wrong one --
    // which reads as "50 people on this campaign's list" and is wrong in a way
    // that looks entirely plausible right up until the last page.
    Supporter::factory()->count(60)->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('supporters.data', 50)
            ->where('supporters.total', 60)
            // The page size, pinned where a reader of this test can see it. A
            // change to PER_PAGE is a change to what an operator is handed, so
            // it should have to be made here as well as in the controller.
            ->where('supporters.per_page', 50)
            ->where('supporters.current_page', 1)
            ->where('supporters.last_page', 2)
        );
});

test('a second page carries the rest of the list, showing nobody twice and skipping nobody', function (): void {
    // **Every supporter shares one created_at**, which is the case an import
    // produces rather than a contrived one: a file's rows are written inside a
    // single transaction and take the same timestamp. Under LIMIT/OFFSET an
    // order that is not total is not a cosmetic wobble -- a row that drifts from
    // the end of page one to the start of page two is served twice, and one
    // drifting the other way is served to nobody.
    //
    // **This test does NOT detect a missing tiebreak, and the correction is
    // recorded rather than quietly dropped.** It was written believing it did.
    // Measured: deleting `orderByDesc('id')` from the controller leaves this
    // test green, and it stays green with the arrival index reduced to
    // `created_at` alone as well -- PostgreSQL simply returns these sixty rows
    // in a stable order anyway, so the *guarantee* is gone while the *symptom*
    // never appears at this size. The guard that does catch it is `the list
    // arrives newest first, with ties broken so the order is total` above, which
    // reddens on that exact deletion because three rows are sorted rather than
    // read from the index and an unstable sort shows.
    //
    // What this test genuinely guards is the paging arithmetic on top of
    // whatever order it is given: the sizes of the two pages, that they do not
    // overlap, and that between them they account for every row. Broken twice,
    // and this test reddens as an *error* rather than a failure under the first
    // of them, which is the distinction Step 5 found a break harness can miss:
    // sending the whole list unpaginated raises "Undefined array key data" here,
    // and moving the page size to 25 fails the page-size assertion.
    $arrived = now()->subDay();

    $listed = Supporter::factory()->count(60)->create(['created_at' => $arrived]);

    $operator = User::factory()->create();

    $pageOf = function (int $page) use ($operator): array {
        $response = $this->actingAs($operator)
            ->get($this->campaignUrl('/supporters?page='.$page))
            ->assertOk();

        return collect($response->viewData('page')['props']['supporters']['data'])
            ->pluck('email')
            ->all();
    };

    $first = $pageOf(1);
    $second = $pageOf(2);

    expect($first)->toHaveCount(50)
        ->and($second)->toHaveCount(10);

    // Nobody twice...
    expect(array_intersect($first, $second))->toBe([]);

    // ...and nobody missing. Asserted as a set against what was actually
    // written, so a page that silently dropped a row -- or served the same
    // fifty twice -- cannot satisfy it.
    $seen = collect([...$first, ...$second])->sort()->values()->all();
    $written = $listed->pluck('email')->sort()->values()->all();

    expect($seen)->toBe($written);
});

test('the last page is reached by following the links the page carries', function (): void {
    // The controls the operator actually clicks, rather than a page number this
    // test made up. On page one there is nowhere previous to go and the absence
    // is part of the contract -- the template renders a disabled button off it
    // rather than a link, so a null that became an empty string would put a
    // live control on the page that navigates nowhere.
    Supporter::factory()->count(60)->create();

    $operator = User::factory()->create();

    $response = $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters'))
        ->assertOk();

    $paginated = $response->viewData('page')['props']['supporters'];

    expect($paginated['prev_page_url'])->toBeNull()
        ->and($paginated['next_page_url'])->toContain('page=2');

    $second = $this->actingAs($operator)
        ->get($paginated['next_page_url'])
        ->assertOk();

    $secondPage = $second->viewData('page')['props']['supporters'];

    expect($secondPage['current_page'])->toBe(2)
        ->and($secondPage['data'])->toHaveCount(10)
        ->and($secondPage['next_page_url'])->toBeNull()
        ->and($secondPage['prev_page_url'])->toContain('page=1');
});
