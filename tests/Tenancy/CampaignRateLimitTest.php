<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Fortify\Features;

/**
 * A rate limit has to say which of two things its key names, because the two
 * want opposite answers under database-per-tenant.
 *
 * A key naming a *person* is campaign-scoped: operators live in their own
 * campaign's database (D-1), so one email address is a different human being in
 * each campaign, and one operator id is a different human being again. Keyed
 * without the campaign, a caller who exhausts one campaign's sign-in budget
 * locks that address out of every campaign on the platform.
 *
 * A key naming a *network address* is not campaign-scoped: it identifies one
 * caller wherever they knock, so giving each campaign its own copy would hand
 * anyone with a list of campaign hostnames a fresh budget for every name on it.
 *
 * Two campaigns are used throughout rather than one, because with a single
 * campaign "the first" and "the only" are indistinguishable and every key that
 * silently dropped its campaign would still pass (L-21).
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Enrol an operator inside one campaign's own database.
 */
function enrolIn(string $slug, string $email): void
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    User::factory()->create(['email' => $email]);

    tenancy()->end();
}

test('one address exhausting a campaign sign-in budget does not lock it out of another campaign', function (): void {
    // The same address, enrolled twice — two different people who happen to
    // share an email, which is the ordinary case once operators live per
    // campaign rather than in one shared table.
    enrolIn('harbor-cleanup', 'ada@example.com');
    enrolIn('ridge-restoration', 'ada@example.com');

    // Five wrong passwords fills Harbor's per-person budget exactly.
    foreach (range(1, 5) as $ignored) {
        $this->post('http://harbor-cleanup.test/login', [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ]);
    }

    $refused = $this->post('http://harbor-cleanup.test/login', [
        'email' => 'ada@example.com',
        'password' => 'not-the-password',
    ]);

    // The pairing that makes this a guard rather than a tautology (L-16). A
    // refusal on its own passes just as happily against a limiter keyed on
    // nothing at all, or on the campaign twice over; the second campaign
    // *still working* for the same address is the entire claim, so both halves
    // are stated together and a later edit cannot quietly drop one.
    //
    // This is also what proves Fortify's own campaign-less LoginRateLimiter is
    // not in the pipeline: were it active, its identical five-per-minute budget
    // for `ada@example.com|127.0.0.1` would already be spent, and the sign-in
    // below would be refused no matter how this application keys anything.
    $accepted = $this->post('http://ridge-restoration.test/login', [
        'email' => 'ada@example.com',
        'password' => 'password',
    ]);

    expect($refused->getStatusCode())->toBe(429)
        ->and($accepted->getStatusCode())->not->toBe(429);

    $this->assertAuthenticated();
});

test('one caller cannot buy a fresh address budget by moving to another campaign', function (): void {
    // Thirty sign-in attempts, every one with a different address so that no
    // per-person budget is ever reached, split across two campaigns. Only a
    // limit that ignores the campaign can see all thirty.
    foreach (range(1, 30) as $attempt) {
        $host = $attempt % 2 === 0 ? 'harbor-cleanup.test' : 'ridge-restoration.test';

        $this->post('http://'.$host.'/login', [
            'email' => 'stranger'.$attempt.'@example.com',
            'password' => 'not-the-password',
        ]);
    }

    // The thirty-first, on a campaign that has served only fifteen of them.
    $refused = $this->post('http://harbor-cleanup.test/login', [
        'email' => 'stranger31@example.com',
        'password' => 'not-the-password',
    ]);

    // The pairing again, in the other direction: a *person* who has spent
    // nothing is still refused, which is only true if the budget that ran out
    // belongs to the caller rather than to the account or to the campaign.
    expect($refused->getStatusCode())->toBe(429);
});

test('a campaign cannot spend another campaign two-factor challenge budget', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // Operator ids restart at 1 in every campaign, so the session value this
    // limiter keys on collides across campaigns by construction — the same
    // ambiguity already recorded for the central `sessions.user_id` column,
    // except that here it decides whether one campaign can lock out another.
    tenancy()->initialize($harbor);
    $harborOperator = User::factory()->create(['email' => 'first@harbor-cleanup.test']);
    tenancy()->initialize($ridge);
    $ridgeOperator = User::factory()->create(['email' => 'first@ridge-restoration.test']);
    tenancy()->end();

    expect($harborOperator->getKey())->toBe($ridgeOperator->getKey());

    foreach (range(1, 5) as $ignored) {
        $this->withSession(['login.id' => $harborOperator->getKey()])
            ->post('http://harbor-cleanup.test/two-factor-challenge', ['code' => '000000']);
    }

    $refused = $this->withSession(['login.id' => $harborOperator->getKey()])
        ->post('http://harbor-cleanup.test/two-factor-challenge', ['code' => '000000']);

    $accepted = $this->withSession(['login.id' => $ridgeOperator->getKey()])
        ->post('http://ridge-restoration.test/two-factor-challenge', ['code' => '000000']);

    // The other campaign's operator 1 is a different person and keeps their own
    // budget. Asserting only the refusal would pass against a key that is the
    // operator id alone, which is exactly the bug.
    expect($refused->getStatusCode())->toBe(429)
        ->and($accepted->getStatusCode())->not->toBe(429);
});
