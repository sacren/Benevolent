<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

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
