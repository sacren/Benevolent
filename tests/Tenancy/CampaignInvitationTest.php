<?php

declare(strict_types=1);

use App\Models\OperatorInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Accepting an invitation, asked across the boundary that decided the
 * mechanism (D-53 Axis 1 (i), §7 criteria 2 and 5).
 *
 * **No operator comes into existence in a campaign except by an act of that
 * campaign**, and this is where that is broken both ways and across campaigns:
 * a link minted in one campaign, presented on the other's host, must open
 * nothing and create nothing in either. A signed URL would have failed exactly
 * here -- a relative signature validates on any host, and invitation ids
 * restart at 1 in every campaign, so campaign A's link would resolve against
 * campaign B's own first row. A stored token has no such split because its
 * scope is the campaign's own database, which is the property under test.
 *
 * **Two campaigns, and two invitations in each, never one** (L-21 and the
 * phase's §3 convention). Each campaign's first invitation is id 1 in its own
 * database, so "the right invitation" and "the invitation with that id" are
 * different rows here, which is what makes the refusal able to fail.
 *
 * Provisioned here rather than through the campaign harness, which keeps one
 * campaign per file inside a transaction a switch to a second would purge (L-10).
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    // Caller-keyed and platform-wide (L-24), so not reset by provisioning.
    app('cache')->driver()->flush();
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Invite two people into one campaign and hand back the first one's token.
 */
function invitationTokenIn(string $slug, string $email, string $bystander): string
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    $invitation = OperatorInvitation::factory()->create(['email' => $email]);
    OperatorInvitation::factory()->create(['email' => $bystander]);

    $token = (string) DB::table('operator_invitations')->where('id', $invitation->getKey())->value('token');

    tenancy()->end();

    return $token;
}

/**
 * Who one campaign has as operators, and which of its invitations still hold
 * their credential -- all sent during the test, so inside their lifetime (D-59)
 * and still open.
 *
 * @return array{operators: list<string>, live: list<string>}
 */
function rosterIn(string $slug): array
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    $state = [
        'operators' => User::query()->orderBy('email')->pluck('email')->all(),
        'live' => DB::table('operator_invitations')->whereNotNull('token')->orderBy('email')->pluck('email')->all(),
    ];

    tenancy()->end();

    return $state;
}

/**
 * @return array<string, string>
 */
function joiningDetails(): array
{
    return [
        'name' => 'Ama Boateng',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ];
}

test('one campaign\'s invitation opens nothing on another campaign\'s host, and creates nobody in either', function (): void {
    $harbor = invitationTokenIn('harbor-cleanup', 'harbor.invitee@example.test', 'harbor.other@example.test');
    invitationTokenIn('ridge-restoration', 'ridge.invitee@example.test', 'ridge.other@example.test');

    // Both invitations that matter are id 1 in their own database, so this is
    // the fixture a signed or id-shaped link would get wrong.
    $this->get('http://ridge-restoration.test/invitation/'.$harbor)->assertNotFound();
    $this->post('http://ridge-restoration.test/invitation/'.$harbor, joiningDetails())->assertNotFound();

    $this->assertGuest();

    expect(rosterIn('ridge-restoration'))->toBe([
        'operators' => [],
        'live' => ['ridge.invitee@example.test', 'ridge.other@example.test'],
    ])->and(rosterIn('harbor-cleanup'))->toBe([
        'operators' => [],
        // Still live: presenting it on the wrong host did not spend it.
        'live' => ['harbor.invitee@example.test', 'harbor.other@example.test'],
    ]);
});

test('each campaign\'s invitation admits its invitee on its own host, and only there', function (): void {
    // **The positive half**, without which the test above passes against a
    // route that refuses every link on every host.
    $harbor = invitationTokenIn('harbor-cleanup', 'harbor.invitee@example.test', 'harbor.other@example.test');
    $ridge = invitationTokenIn('ridge-restoration', 'ridge.invitee@example.test', 'ridge.other@example.test');

    $this->post('http://harbor-cleanup.test/invitation/'.$harbor, joiningDetails())->assertRedirect();
    auth()->logout();
    $this->post('http://ridge-restoration.test/invitation/'.$ridge, joiningDetails())->assertRedirect();

    expect(rosterIn('harbor-cleanup'))->toBe([
        'operators' => ['harbor.invitee@example.test'],
        'live' => ['harbor.other@example.test'],
    ])->and(rosterIn('ridge-restoration'))->toBe([
        'operators' => ['ridge.invitee@example.test'],
        'live' => ['ridge.other@example.test'],
    ]);
});
