<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Tenant;
use App\Models\Unsubscribe;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * The blast module's isolation guarantee restated where it is actually
 * exercised: over HTTP, by an operator who is genuinely signed in somewhere.
 *
 * CampaignBlastIsolationTest makes the same claim through the model, by
 * switching campaigns in-process and asking one query twice. That proves the
 * storage is separate. It cannot prove the *page* keeps them separate, because
 * it issues no request, resolves no campaign from a Host header, and never asks
 * the authentication guard who the operator is. This file is the other half,
 * and it is the shape CampaignSupporterHttpIsolationTest established for the
 * first module that had real data behind it.
 *
 * **The hazard is sharper here than it was for supporters, and it has a second
 * shape they did not have.** A supporter is reached by a page that lists what
 * the campaign owns. A blast is also reached *by id* -- `/blasts/{blast}/edit`
 * and `/blasts/{blast}/send` bind a bare integer through route model binding --
 * and blast ids restart at 1 in every campaign, so one campaign's first blast
 * and another's are both `blasts.id = 1`. Nothing in the URL says which
 * campaign is meant. The answer has to come from the Host header, and the only
 * thing that makes it come from there is that the connection was switched
 * before the binding ran.
 *
 * **What makes that worth a test rather than a comment**: the failure is not a
 * 404 anybody would notice. It is an operator opening what they believe is
 * their own draft and editing, or sending, another campaign's message to
 * another campaign's supporters. There is no later change that repairs a blast
 * that has gone out.
 *
 * **actingAs() cannot ask the identity question and would answer it wrongly.**
 * It binds a User object straight into the guard, so nobody is ever looked up
 * and the cross-campaign lookup is skipped. These tests sign in for real,
 * through the login route, on the campaign's own hostname.
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
 * Put one Owner and one draft blast into a campaign, and hand back both so a
 * test can sign in and address the blast by its id.
 *
 * @return array{0: User, 1: Blast}
 */
function stock(Tenant $campaign, string $operatorEmail, string $subject): array
{
    tenancy()->initialize($campaign);

    $operator = User::factory()->owner()->create(['email' => $operatorEmail]);
    $blast = Blast::factory()->create(['subject' => $subject]);

    tenancy()->end();

    return [$operator, $blast];
}

test('a signed-in operator is served their own campaign blasts and never another campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborBlast] = stock($harbor, 'operator@harbor-cleanup.test', 'Dredging starts Monday');
    [, $ridgeBlast] = stock($ridge, 'operator@ridge-restoration.test', 'The ridge path reopens');

    // The premise the second test rests on, asserted here rather than assumed:
    // the two blasts really do share an id, so a URL carrying a bare id is
    // ambiguous across campaigns. Were ids ever to stop colliding, both tests
    // would keep passing while testing nothing, so the collision is stated.
    expect($harborBlast->getKey())->toBe($ridgeBlast->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    // The positive half, in the same run and through the same session. Without
    // it the negative below is satisfied by a session that never authenticated,
    // by a route that does not exist, and by a page that refuses everybody.
    $this->get('http://harbor-cleanup.test/blasts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('blasts/Index')
            ->has('blasts', 1)
            ->where('blasts.0.subject', 'Dredging starts Monday')
            ->where('auth.user.email', 'operator@harbor-cleanup.test')
        );

    // The negative, and the one the file is named for. Asserted against the
    // rendered response rather than only against the prop count, because a
    // count of one is also what a page showing the *wrong* single blast has.
    $this->get('http://harbor-cleanup.test/blasts')
        ->assertDontSee('The ridge path reopens');
});

test('a blast addressed by an id both campaigns use resolves against the host, never across the two', function (): void {
    // The blast-specific half, and the reason this file exists rather than a
    // second supporter test. Route model binding receives `1` and has to decide
    // which campaign's blast 1 that is; the URL cannot tell it, and the
    // difference between the two answers is which supporters get a message.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborBlast] = stock($harbor, 'operator@harbor-cleanup.test', 'Dredging starts Monday');
    [, $ridgeBlast] = stock($ridge, 'operator@ridge-restoration.test', 'The ridge path reopens');

    expect($harborBlast->getKey())->toBe($ridgeBlast->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    // Asking for "blast 1" on this campaign's host, which is also the id of the
    // other campaign's blast. The subject is what says which one answered.
    $this->get('http://harbor-cleanup.test/blasts/'.$harborBlast->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('blasts/Edit')
            ->where('blast.subject', 'Dredging starts Monday')
        )
        ->assertDontSee('The ridge path reopens');

    // **The same id, on the other campaign's host, answered as that campaign.**
    // Two in-process artifacts stack here and only one of them is obvious. The
    // session crosses hostnames because the test client does not model the
    // cookie's host scope; and the guard caches the operator it resolved at
    // login for the life of the process, so a second request never looks anyone
    // up -- something php-fpm cannot do, since every request is its own
    // process. Left in place that makes this request answer as Harbor's
    // operator while rendering Ridge's blast, which reads exactly like a
    // cross-campaign leak and is entirely the harness's doing. Forgetting the
    // guards is this file's tenancy()->end(): L-13's discipline applied to
    // authentication rather than to campaign context.
    Auth::forgetGuards();

    $this->get('http://ridge-restoration.test/blasts/'.$ridgeBlast->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Answered as the other campaign's own operator, not as the one who
            // signed in. The identity did not travel; only the id did.
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            ->where('blast.subject', 'The ridge path reopens')
        )
        ->assertDontSee('Dredging starts Monday');

    // And what a real browser gets, since it never sends that cookie here.
    $this->flushSession();
    Auth::forgetGuards();

    // Asserted by substring rather than as a whole URL: the generator appends
    // APP_URL's port, so spelling the address out here would fail on the port
    // rather than on the redirect it is meant to check.
    $this->get('http://ridge-restoration.test/blasts/'.$ridgeBlast->getKey().'/edit')
        ->assertRedirectContains('ridge-restoration.test')
        ->assertRedirectContains('/login');
});

test('the outcome numbers on the list are drawn from the host campaign alone', function (): void {
    // **DEC-1 restated for what Phase 5 Step 4 put on this page.** The blast
    // list now carries three numbers that are counted rather than stored --
    // how many of a blast's copies could name themselves, how many people used
    // one of those links to leave, and how many withdrawals named no message
    // at all. Each is an aggregate over a *different* table from `blasts`, and
    // the last of them is a campaign-wide count answering no blast at all.
    //
    // **That last one is why this test exists rather than riding on the two
    // above.** A per-blast count that leaked would show against a row, where
    // the test above would already see the wrong subject. A campaign-level
    // figure has no row to be wrong on: it would simply read too high, and
    // nothing else on the page would look different.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborBlast] = stock($harbor, 'operator@harbor-cleanup.test', 'Dredging starts Monday');
    [, $ridgeBlast] = stock($ridge, 'operator@ridge-restoration.test', 'The ridge path reopens');

    expect($harborBlast->getKey())->toBe($ridgeBlast->getKey());

    // Harbor: one copy, left through its own link, and one withdrawal that
    // named no message.
    tenancy()->initialize($harbor);
    $harborCopy = BlastRecipient::factory()->ofBlast($harborBlast)->sent()->create();
    Unsubscribe::create(['blast_recipient_id' => $harborCopy->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    tenancy()->end();

    // Ridge: deliberately larger on every axis, so any leak reads high rather
    // than merely different, and so no assertion below can be satisfied by a
    // coincidence of equal numbers.
    tenancy()->initialize($ridge);
    $ridgeCopies = BlastRecipient::factory()->count(4)->ofBlast($ridgeBlast)->sent()->create();
    Unsubscribe::create(['blast_recipient_id' => $ridgeCopies[0]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => $ridgeCopies[1]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => $ridgeCopies[2]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    tenancy()->end();

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('http://harbor-cleanup.test/blasts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('blasts/Index')
            ->has('blasts', 1)
            ->where('blasts.0.subject', 'Dredging starts Monday')
            // Harbor's own, every one of them. A leak in either direction moves
            // these off the values Harbor alone can account for.
            ->where('blasts.0.reached_count', 1)
            ->where('blasts.0.attributable_count', 1)
            ->where('blasts.0.withdrawn_count', 1)
            ->where('unattributedWithdrawals', 1)
        );
});

test('a withdrawal recorded in one campaign is invisible in the other, counted from its own host', function (): void {
    // The other direction, through the other host in the same run. Without it
    // the test above is satisfied by a page that reads one campaign's numbers
    // for everybody -- which, if that campaign happened to be Harbor, is
    // exactly what a correct page looks like from Harbor's side.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborBlast] = stock($harbor, 'operator@harbor-cleanup.test', 'Dredging starts Monday');
    [, $ridgeBlast] = stock($ridge, 'operator@ridge-restoration.test', 'The ridge path reopens');

    tenancy()->initialize($harbor);
    $harborCopy = BlastRecipient::factory()->ofBlast($harborBlast)->sent()->create();
    Unsubscribe::create(['blast_recipient_id' => $harborCopy->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    tenancy()->end();

    tenancy()->initialize($ridge);
    $ridgeCopies = BlastRecipient::factory()->count(4)->ofBlast($ridgeBlast)->sent()->create();
    Unsubscribe::create(['blast_recipient_id' => $ridgeCopies[0]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => $ridgeCopies[1]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => $ridgeCopies[2]->getKey()]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    Unsubscribe::create(['blast_recipient_id' => null]);
    tenancy()->end();

    $this->post('http://ridge-restoration.test/login', [
        'email' => 'operator@ridge-restoration.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('http://ridge-restoration.test/blasts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('blasts', 1)
            ->where('blasts.0.subject', 'The ridge path reopens')
            ->where('blasts.0.reached_count', 4)
            ->where('blasts.0.attributable_count', 4)
            ->where('blasts.0.withdrawn_count', 3)
            ->where('unattributedWithdrawals', 3)
        );
});
