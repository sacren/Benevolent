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
            ->has('supporters', 2)
        );

    $sent = collect($response->viewData('page')['props']['supporters'])
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
            ->where('supporters.0.email', $newer->email)
            ->where('supporters.1.email', $second->email)
            ->where('supporters.2.email', $first->email)
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
            ->has('supporters', 1)
            ->where('supporters.0.email', $nameless->email)
            ->where('supporters.0.name', null)
            ->where('supporters.0.given_name', null)
            ->where('supporters.0.family_name', null)
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
