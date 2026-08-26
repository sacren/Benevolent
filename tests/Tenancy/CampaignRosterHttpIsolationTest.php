<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * The roster's isolation guarantee, asked over HTTP by an Owner who is really
 * signed in (§7 criterion 5, DEC-1 restated for the sixth module).
 *
 * **The roster is the first page in this product whose rows are operators,
 * and operators are the one thing each campaign's own authentication reads.**
 * A leak here would not be another campaign's supporters on the wrong page; it
 * would be another campaign's staff list -- names and addresses of the people
 * who run it, and who they have invited -- shown to a stranger who governs
 * somewhere else. Two campaigns, each with an Owner, an invited operator and
 * an invitation still waiting, so every list on the page has a row from the
 * other campaign that it must not show.
 *
 * **And the roster's acts address rows by id**, which restarts at 1 in every
 * campaign, so an Owner acting on "invitation 2" must reach this campaign's
 * invitation 2 and never the other's; the second test is that question.
 *
 * **actingAs() cannot ask the identity question** (CampaignBlastHttpIsolationTest
 * says why), so this signs in through the login route on the campaign's own
 * hostname.
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
 * An Owner, somebody they admitted, and somebody still waiting to join.
 */
function staffRosterIn(string $slug): void
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    $owner = User::factory()->owner()->create(['name' => "Owner of {$slug}", 'email' => "owner@{$slug}.test"]);
    User::factory()->create(['name' => "Helper at {$slug}", 'email' => "helper@{$slug}.test"]);

    OperatorInvitation::factory()->invitedBy($owner)->accepted()->create(['email' => "helper@{$slug}.test"]);
    OperatorInvitation::factory()->invitedBy($owner)->create(['email' => "waiting@{$slug}.test"]);

    tenancy()->end();
}

test('a signed-in owner is shown their own campaign\'s roster and never another campaign\'s', function (): void {
    staffRosterIn('harbor-cleanup');
    staffRosterIn('ridge-restoration');

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'owner@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    // The positive half, in the same run and through the same session: the
    // page answered as Harbor, with Harbor's people on it.
    $this->get('http://harbor-cleanup.test/operators')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operators/Index')
            ->where('auth.user.email', 'owner@harbor-cleanup.test')
            ->has('operators', 2)
            ->where('operators.0.email', 'helper@harbor-cleanup.test')
            ->where('operators.0.admitted', ['by' => 'owner@harbor-cleanup.test'])
            ->where('operators.1.email', 'owner@harbor-cleanup.test')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'waiting@harbor-cleanup.test')
        )
        // And the negative against the rendered response, because a count of
        // two is also what a page showing the wrong two people has.
        ->assertDontSee('ridge-restoration.test');
});

test('a withdrawal addressed by an id both campaigns use takes back this campaign\'s invitation and never the other\'s', function (): void {
    // The invitation is addressed by its row id, which restarts at 1 in every
    // campaign, so the URL alone cannot say which campaign's invitation it
    // names -- the Host header has to, the way it does for a blast.
    staffRosterIn('harbor-cleanup');
    staffRosterIn('ridge-restoration');

    $waitingId = function (string $slug): int {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());
        $id = (int) OperatorInvitation::query()->where('email', "waiting@{$slug}.test")->value('id');
        tenancy()->end();

        return $id;
    };

    $stillLive = function (string $slug): bool {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());
        $live = OperatorInvitation::query()->where('email', "waiting@{$slug}.test")->whereNotNull('token')->exists();
        tenancy()->end();

        return $live;
    };

    // The premise, stated rather than assumed: the two invitations share an id.
    expect($waitingId('harbor-cleanup'))->toBe($waitingId('ridge-restoration'));

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'owner@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->delete('http://harbor-cleanup.test/operators/invitations/'.$waitingId('harbor-cleanup'))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Harbor's is withdrawn; Ridge's, which carries the same id, still works.
    expect($stillLive('harbor-cleanup'))->toBeFalse()
        ->and($stillLive('ridge-restoration'))->toBeTrue();
});

test('a role change addressed by an operator id both campaigns use changes this campaign\'s operator and never the other\'s', function (): void {
    staffRosterIn('harbor-cleanup');
    staffRosterIn('ridge-restoration');

    $helper = function (string $slug): User {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());
        $helper = User::query()->where('email', "helper@{$slug}.test")->sole();
        tenancy()->end();

        return $helper;
    };

    // The premise, stated rather than assumed: the two helpers share an id.
    expect($helper('harbor-cleanup')->getKey())->toBe($helper('ridge-restoration')->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'owner@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->patch('http://harbor-cleanup.test/operators/'.$helper('harbor-cleanup')->getKey(), ['role' => 'owner'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($helper('harbor-cleanup')->role)->toBe(OperatorRole::Owner)
        ->and($helper('ridge-restoration')->role)->toBe(OperatorRole::Staff);
});

test('a removal addressed by an operator id both campaigns use removes this campaign\'s operator and never the other\'s', function (): void {
    staffRosterIn('harbor-cleanup');
    staffRosterIn('ridge-restoration');

    $helperId = function (string $slug): ?int {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());
        $id = User::query()->where('email', "helper@{$slug}.test")->value('id');
        tenancy()->end();

        return $id === null ? null : (int) $id;
    };

    $id = $helperId('harbor-cleanup');

    // The premise, stated rather than assumed: the two helpers share an id.
    expect($id)->not->toBeNull()->toBe($helperId('ridge-restoration'));

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'owner@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->delete('http://harbor-cleanup.test/operators/'.$id)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($helperId('harbor-cleanup'))->toBeNull()
        ->and($helperId('ridge-restoration'))->toBe($id);
});

test('an owner who spends their invitation budget in one campaign leaves the owner with the same id in another theirs', function (): void {
    // **The invite-operators key names a person, and a person is an id within
    // one campaign (L-24).** Both Owners below are operator 1 in their own
    // campaign's database, so a key built from the id alone would let Harbor's
    // Owner spend Ridge's Owner's budget -- a limit on one person that locks out
    // a stranger somewhere else.
    app('cache')->driver()->flush();
    Mail::fake();

    staffRosterIn('harbor-cleanup');
    staffRosterIn('ridge-restoration');

    $ownerId = function (string $slug): int {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());
        $id = (int) User::query()->where('email', "owner@{$slug}.test")->value('id');
        tenancy()->end();

        return $id;
    };

    // The precondition the test rests on, asserted rather than assumed.
    expect($ownerId('harbor-cleanup'))->toBe(1)
        ->and($ownerId('ridge-restoration'))->toBe(1);

    $this->post('http://harbor-cleanup.test/login', ['email' => 'owner@harbor-cleanup.test', 'password' => 'password'])->assertRedirect();

    for ($i = 1; $i <= 20; $i++) {
        $this->post('http://harbor-cleanup.test/operators/invite', ['email' => "harbor{$i}@example.test", 'role' => 'staff'])
            ->assertSessionHasNoErrors();
    }

    $this->post('http://harbor-cleanup.test/operators/invite', ['email' => 'harbor-extra@example.test', 'role' => 'staff'])
        ->assertSessionHasErrors('email');

    $this->post('http://harbor-cleanup.test/logout')->assertRedirect();
    $this->post('http://ridge-restoration.test/login', ['email' => 'owner@ridge-restoration.test', 'password' => 'password'])->assertRedirect();

    $this->post('http://ridge-restoration.test/operators/invite', ['email' => 'ridge-first@example.test', 'role' => 'staff'])
        ->assertSessionHasNoErrors();

    tenancy()->initialize(Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail());
    expect(OperatorInvitation::query()->where('email', 'ridge-first@example.test')->exists())->toBeTrue();
    tenancy()->end();
});
