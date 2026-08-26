<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Operators\OperatorInvitationMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Where a campaign's first Owner comes from (D-53 Axis 3): the platform invites
 * them, from the console, while the campaign has nobody.
 *
 * **Two campaigns throughout** (L-21 and the phase's §3 convention): the
 * command is addressed by slug and runs centrally, so "it wrote to the named
 * campaign" and "it wrote to a campaign" are different claims only while a
 * second campaign is there to be written to by mistake.
 *
 * In the Tenancy suite because the command enters campaigns itself, from
 * central, which is the context a platform operator's shell runs in.
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    app('cache')->driver()->flush();

    Mail::fake();
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Every invitation one campaign holds, as the command left it.
 *
 * @return list<array{email: string, role: string, live: bool, invited_by: string|null}>
 */
function invitationsIn(string $slug): array
{
    tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

    $rows = DB::table('operator_invitations')->orderBy('id')->get()
        ->map(fn (object $row): array => [
            'email' => $row->email,
            'role' => $row->role,
            'live' => $row->token !== null,
            'invited_by' => $row->invited_by_label,
        ])->all();

    tenancy()->end();

    return $rows;
}

test('the platform invites an empty campaign\'s first owner, and only that campaign', function (): void {
    // **Ridge, the campaign provisioned second.** Invite Harbor here and a
    // command that entered whichever campaign came first, rather than the one
    // named, would pass: the right campaign and the first campaign would be
    // the same row.
    $exit = Artisan::call('campaign:invite-owner', ['slug' => 'ridge-restoration', 'email' => 'Director@Ridge-Restoration.test']);

    expect($exit)->toBe(0)
        ->and(invitationsIn('ridge-restoration'))->toBe([[
            'email' => 'Director@Ridge-Restoration.test',
            'role' => OperatorRole::Owner->value,
            'live' => true,
            // On nobody's recorded authority: the platform is not an operator.
            'invited_by' => null,
        ]])
        // **The campaign the command must not have written to.**
        ->and(invitationsIn('harbor-cleanup'))->toBe([]);

    Mail::assertSent(OperatorInvitationMessage::class, 1);
    Mail::assertNothingQueued();
});

test('the link it mails names the campaign\'s own host, though no request carried one', function (): void {
    // Run from the console there is no Host header to build a URL from, so this
    // is CampaignHostTenancyBootstrapper's forced root doing the work -- the
    // case its docblock was written for.
    Artisan::call('campaign:invite-owner', ['slug' => 'harbor-cleanup', 'email' => 'director@harbor-cleanup.test']);

    $body = (string) Mail::sent(OperatorInvitationMessage::class)->first()->render();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    $token = (string) DB::table('operator_invitations')->value('token');
    tenancy()->end();

    preg_match('~https?://\S+/invitation/\S+~', $body, $link);

    expect($link)->toHaveCount(1)
        ->and(parse_url($link[0], PHP_URL_HOST))->toBe('harbor-cleanup.test')
        ->and($link[0])->toEndWith('/invitation/'.$token)
        ->and($body)->toContain('as an Owner');
});

test('accepting it makes them the campaign\'s first owner', function (): void {
    Artisan::call('campaign:invite-owner', ['slug' => 'harbor-cleanup', 'email' => 'director@harbor-cleanup.test']);

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    $token = (string) DB::table('operator_invitations')->value('token');
    tenancy()->end();

    $this->post('http://harbor-cleanup.test/invitation/'.$token, [
        'name' => 'Harbor Director',
        'password' => 'a-memorable-passphrase',
        'password_confirmation' => 'a-memorable-passphrase',
    ])->assertRedirect();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    $owner = User::query()->sole();
    tenancy()->end();

    expect($owner->email)->toBe('director@harbor-cleanup.test')
        ->and($owner->role)->toBe(OperatorRole::Owner);
});

test('a campaign that already has an operator is its own to admit anybody to', function (): void {
    tenancy()->initialize(Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail());
    User::factory()->create(['email' => 'helper@ridge-restoration.test']);
    tenancy()->end();

    $exit = Artisan::call('campaign:invite-owner', ['slug' => 'ridge-restoration', 'email' => 'stranger@example.test']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('already has operators')
        ->and(invitationsIn('ridge-restoration'))->toBe([]);

    Mail::assertNothingOutgoing();

    // **The positive half in the same run**: the empty campaign beside it is
    // still invitable, so the refusal is about Ridge and not about the command.
    expect(Artisan::call('campaign:invite-owner', ['slug' => 'harbor-cleanup', 'email' => 'director@harbor-cleanup.test']))->toBe(0);
});

test('the credential is mailed and never printed', function (): void {
    // Printed, it would sit in a platform operator's scrollback and shell
    // history as a live key to a campaign's governance.
    Artisan::call('campaign:invite-owner', ['slug' => 'harbor-cleanup', 'email' => 'director@harbor-cleanup.test']);

    $output = Artisan::output();

    tenancy()->initialize(Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail());
    $token = (string) DB::table('operator_invitations')->value('token');
    tenancy()->end();

    expect($token)->not->toBe('')
        ->and($output)->toContain('director@harbor-cleanup.test')
        ->and($output)->not->toContain($token);
});

test('an unknown campaign or an unusable address changes nothing anywhere', function (): void {
    expect(Artisan::call('campaign:invite-owner', ['slug' => 'no-such-campaign', 'email' => 'director@example.test']))->toBe(1)
        ->and(Artisan::call('campaign:invite-owner', ['slug' => 'harbor-cleanup', 'email' => 'not an address']))->toBe(1)
        ->and(invitationsIn('harbor-cleanup'))->toBe([])
        ->and(invitationsIn('ridge-restoration'))->toBe([]);

    Mail::assertNothingOutgoing();
});

test('a first owner who never used their invitation in time can be sent another, and the first stops working', function (): void {
    // **The case a lifetime (D-59) must not strand**: a campaign provisioned,
    // its first Owner invited, and the link left unopened past its lifetime.
    // Nobody inside the campaign can invite anybody, so the platform is the
    // only one who can send a fresh link.
    Artisan::call('campaign:invite-owner', ['slug' => 'ridge-restoration', 'email' => 'director@ridge-restoration.test']);

    // Inside its lifetime the same command is refused, so what follows is
    // the lifetime at work and not a command that never refused anything.
    expect(Artisan::call('campaign:invite-owner', ['slug' => 'ridge-restoration', 'email' => 'director@ridge-restoration.test']))->toBe(1)
        ->and(Artisan::output())->toContain('has not been used');

    $this->travel(OperatorInvitation::LIFETIME_DAYS)->days();
    $this->travel(1)->minutes();

    // Harbor invites its own first Owner now, so it holds a live invitation
    // that re-inviting Ridge's director must not have touched.
    Artisan::call('campaign:invite-owner', ['slug' => 'harbor-cleanup', 'email' => 'director@harbor-cleanup.test']);

    $exit = Artisan::call('campaign:invite-owner', ['slug' => 'ridge-restoration', 'email' => 'director@ridge-restoration.test']);

    expect($exit)->toBe(0)
        // The lapsed invitation is kept and withdrawn, and the fresh one is
        // the only one whose link works.
        ->and(array_column(invitationsIn('ridge-restoration'), 'live'))->toBe([false, true])
        ->and(array_column(invitationsIn('harbor-cleanup'), 'live'))->toBe([true]);
});
