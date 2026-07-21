<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * The isolation guarantee restated where it is actually exercised: over HTTP,
 * by an operator who is genuinely signed in somewhere.
 *
 * CampaignSupporterIsolationTest makes the same claim through the model, by
 * switching campaigns in-process and asking one query twice. That proves the
 * storage is separate. It cannot prove the *page* keeps them separate, because
 * it issues no request, resolves no campaign from a Host header, and never asks
 * the authentication guard who the operator is.
 *
 * The hazard is specific. Operators live in their own campaign's database, so
 * ids restart at 1 in every campaign — one campaign's Owner and another's are
 * both `users.id = 1`. Sessions are central infrastructure and record an
 * operator as a bare id. So the question is what a session established in one
 * campaign resolves to when that id is looked up in a different campaign's
 * database.
 *
 * **actingAs() cannot ask that question and would answer it wrongly.** It binds
 * a User object straight into the guard, so nobody is ever looked up and the
 * cross-campaign lookup — the whole hazard — is skipped. These tests therefore
 * sign in for real, through the login route, on the campaign's own hostname.
 *
 * Provisions its own campaigns rather than using the campaign harness, for
 * L-10's reason: that trait holds one campaign per file inside a transaction
 * that switching to a second campaign would purge.
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Put one operator and one supporter into a campaign, and hand back the
 * operator so a test can sign in as them.
 */
function populate(Tenant $campaign, string $operatorEmail, string $supporterEmail): User
{
    tenancy()->initialize($campaign);

    $operator = User::factory()->owner()->create(['email' => $operatorEmail]);
    Supporter::factory()->create(['email' => $supporterEmail]);

    tenancy()->end();

    return $operator;
}

test('a signed-in operator is served their own campaign supporters and never another campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    $harborOperator = populate($harbor, 'operator@harbor-cleanup.test', 'signer@harbor-cleanup.test');
    $ridgeOperator = populate($ridge, 'operator@ridge-restoration.test', 'signer@ridge-restoration.test');

    // The premise the hazard rests on, asserted rather than assumed: the two
    // operators really do share an id, so a session carrying a bare id is
    // ambiguous across campaigns. Were ids ever to stop colliding, this test
    // would keep passing while testing nothing, so the collision is stated.
    expect($harborOperator->getKey())->toBe($ridgeOperator->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    // The positive half, in the same run and through the same session. Without
    // it the negative below is satisfied by a session that never authenticated,
    // by a route that does not exist, and by a page that refuses everybody.
    $this->get('http://harbor-cleanup.test/supporters')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('supporters', 1)
            ->where('supporters.0.email', 'signer@harbor-cleanup.test')
            ->where('auth.user.email', 'operator@harbor-cleanup.test')
        );

    // The negative, and the one the file is named for: the other campaign's
    // supporters were never in reach, and this campaign's page cannot be made
    // to show them.
    $this->get('http://harbor-cleanup.test/supporters')
        ->assertDontSee('signer@ridge-restoration.test');
});

test('a session carried to another campaign resolves against that campaign, never across the two', function (): void {
    // What a browser does here is nothing: the session cookie is host-only, so
    // it is never sent to the other campaign's hostname at all. The test client
    // does not model cookie scope and carries the session regardless, which is
    // a harness artifact of exactly L-13's family -- and it is worth keeping,
    // because it exercises the one thing cookie scope would otherwise hide.
    //
    // **Measured, and it is not a leak:** the guard re-resolves the id against
    // whichever campaign is serving the request, so the request is answered as
    // *that* campaign's operator, with that campaign's supporters. The first
    // campaign's data is never reachable. What it does mean is that a session
    // id which somehow arrives at another campaign's host authenticates as
    // whoever holds that id there -- so the cookie's host scope is not a
    // convenience, it is the boundary.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    populate($harbor, 'operator@harbor-cleanup.test', 'signer@harbor-cleanup.test');
    populate($ridge, 'operator@ridge-restoration.test', 'signer@ridge-restoration.test');

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    // **Two in-process artifacts stack here, and only one of them was obvious.**
    // The first is the session crossing hostnames, described above. The second
    // is that the guard caches the operator it resolved at login for the life
    // of the process, so a second request never looks anyone up -- something
    // php-fpm cannot do, since every request is its own process. Left in place
    // it makes this request answer as the *first* campaign's operator while
    // rendering the *second* campaign's supporters, which reads exactly like a
    // cross-campaign leak and is entirely the harness's doing. Forgetting the
    // guards is this file's tenancy()->end(): the L-13 discipline applied to
    // authentication rather than to campaign context.
    Auth::forgetGuards();

    $this->get('http://ridge-restoration.test/supporters')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Answered as the other campaign's own operator, not as the one who
            // signed in. The identity did not travel; only the id did.
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            ->has('supporters', 1)
            ->where('supporters.0.email', 'signer@ridge-restoration.test')
        )
        ->assertDontSee('signer@harbor-cleanup.test');

    // And what a real browser gets, since it never sends that cookie here.
    $this->flushSession();
    Auth::forgetGuards();

    // Asserted by substring rather than as a whole URL: the generator appends
    // APP_URL's port, so spelling the address out here would fail on the port
    // rather than on the redirect it is meant to check.
    $this->get('http://ridge-restoration.test/supporters')
        ->assertRedirectContains('ridge-restoration.test')
        ->assertRedirectContains('/login');

    // The configuration invariant behind all of it, and the reason this
    // assertion is in this file rather than in a session test. The behaviour
    // above is safe *because* the cookie never crosses hostnames. Setting a
    // shared parent domain -- the obvious thing to reach for the day someone
    // wants one sign-in across a campaign's several hostnames -- would make the
    // cross-campaign request above reachable from a browser, and an operator of
    // one campaign would silently become whoever holds their id in another.
    // This line goes red on that change, which is the only warning it will get.
    expect(config('session.domain'))->toBeNull();
});
