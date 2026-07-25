<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Paging the supporter list, asked the questions one campaign cannot answer.
 *
 * tests/Campaign/SupporterListTest.php proves that a page carries fifty rows,
 * that the count is of the whole list, and that a second page shows nobody
 * twice — all inside a single campaign the test had already entered. None of
 * that says which campaign the second page came from, or whose hostname the
 * operator is invited to click.
 *
 * **Two campaigns, never one.** Three times in this project a connection or a
 * singleton captured against the first campaign in a process has been invisible
 * to a single-campaign probe (L-15, L-21, and the password broker at Phase 0
 * Step 11), because "the first" and "the only" are the same campaign.
 *
 * **The pagination link is the new read of campaign context, and it is the one
 * worth guarding.** Everything about *which rows* a page carries is inherited
 * from Supporter naming no connection, which CampaignSupporterIsolationTest
 * already states, and paging adds nothing to it. What paging genuinely adds is
 * a URL built per request and handed to the browser to follow — and this
 * application has been bitten before by values derived from APP_URL rather than
 * from the campaign (Blueprint v0.9, where queued mail and the passkey relying
 * party both fell back to the central host).
 *
 * **Measured before it was written, and the hazard is not where it looked.**
 * Laravel resolves the paginator's path from the live request, so today's links
 * already carry the campaign's own hostname; probed across two campaigns in one
 * process, each got its own path and its own rows. So this guard reports no
 * present defect. It exists because two plausible edits break it, and **only one
 * of them is visible to a single campaign** — which is the whole argument for
 * this file rather than another test in the Campaign suite.
 *
 * *Deriving the path from the application URL* — `config('app.url')` or
 * `route('supporters.index')`, the edit somebody makes to "tidy up" a raw URL —
 * sends page two of every campaign to the central host. Broken that way this
 * file goes red naming the host, and the Campaign suite *also* goes red, but on
 * `expected 200, received 302` from following a link off its own hostname. It
 * catches the defect without naming the cause.
 *
 * *Capturing the path once per process* — `self::$path ??= url('/supporters')`,
 * the L-21 shape this project has met three times — is the one that matters.
 * Measured: the **entire Campaign suite stays green at 163 tests**, because with
 * one campaign per file "the first" and "the only" are the same campaign, while
 * this file goes red with the second campaign's page carrying
 * `http://harbor-cleanup.test:8042/supporters`. That break is why the two
 * campaigns here are not ceremony.
 *
 * Provisions its own campaigns rather than using the campaign harness, for
 * L-10's reason: that trait holds one campaign per file inside a transaction
 * that switching to a second campaign would purge.
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

test('each campaign pages its own list, over its own hostname, in one process', function (): void {
    // Named differently from the helpers in the sibling Tenancy files: Pest
    // compiles every test file into one process, so two global functions sharing
    // a name is a fatal redeclaration that aborts the run rather than failing a
    // test.
    $fill = function (string $slug, string $operatorEmail): void {
        tenancy()->initialize(Tenant::query()->where('slug', $slug)->firstOrFail());

        User::factory()->owner()->create(['email' => $operatorEmail]);

        // Sixty against a page of fifty, so there is genuinely a second page to
        // ask for. Every address names its own campaign, so a row from the wrong
        // database is unmistakable rather than something to be inferred.
        for ($n = 0; $n < 60; $n++) {
            Supporter::factory()->create(['email' => 'signer'.$n.'@'.$slug.'.test']);
        }

        tenancy()->end();
    };

    $fill('harbor-cleanup', 'operator@harbor-cleanup.test');
    $fill('ridge-restoration', 'operator@ridge-restoration.test');

    $pageTwoOf = function (string $slug, string $operatorEmail): array {
        // Signed in for real, on the campaign's own hostname, for the reason
        // CampaignSupporterHttpIsolationTest gives at length: actingAs() binds a
        // User object into the guard and never looks anybody up, which skips the
        // cross-campaign resolution entirely.
        $this->post('http://'.$slug.'.test/login', [
            'email' => $operatorEmail,
            'password' => 'password',
        ])->assertRedirect();

        // The guard caches the operator it resolved for the life of the process,
        // which php-fpm cannot do because every request is its own process.
        // Left in place, the second campaign's request answers as the first
        // campaign's operator — L-13's discipline applied to authentication.
        $response = $this->get('http://'.$slug.'.test/supporters?page=2')->assertOk();

        Auth::forgetGuards();
        tenancy()->end();

        return $response->viewData('page')['props']['supporters'];
    };

    $harbor = $pageTwoOf('harbor-cleanup', 'operator@harbor-cleanup.test');
    $ridge = $pageTwoOf('ridge-restoration', 'operator@ridge-restoration.test');

    // The positive half for each campaign, in the same run and the same process.
    // Without it every negative below is satisfied by a page that returned
    // nothing at all.
    expect($harbor['total'])->toBe(60)
        ->and($harbor['data'])->toHaveCount(10)
        ->and($ridge['total'])->toBe(60)
        ->and($ridge['data'])->toHaveCount(10);

    // The rows on the *second* page came from the right database. The first page
    // is already covered by the isolation file; this asks the same question of a
    // query carrying an OFFSET, which is the part paging added.
    $harborAddresses = collect($harbor['data'])->pluck('email');
    $ridgeAddresses = collect($ridge['data'])->pluck('email');

    expect($harborAddresses->every(fn (string $email): bool => str_ends_with($email, '@harbor-cleanup.test')))->toBeTrue()
        ->and($ridgeAddresses->every(fn (string $email): bool => str_ends_with($email, '@ridge-restoration.test')))->toBeTrue()
        ->and($harborAddresses->intersect($ridgeAddresses)->all())->toBe([]);

    // And the link the operator is invited to click stays inside their own
    // campaign. This is the assertion the alternative implementation reddens:
    // a path derived from the application URL would put the central host here,
    // identically for both campaigns, while every row assertion above carried on
    // passing.
    expect($harbor['path'])->toContain('harbor-cleanup.test')
        ->and($harbor['prev_page_url'])->toContain('harbor-cleanup.test')
        ->and($harbor['path'])->not->toContain('ridge-restoration.test')
        ->and($ridge['path'])->toContain('ridge-restoration.test')
        ->and($ridge['prev_page_url'])->toContain('ridge-restoration.test')
        ->and($ridge['path'])->not->toContain('harbor-cleanup.test');

    // The premise the link assertions rest on, stated rather than assumed: the
    // two campaigns really are on different hostnames, and neither is the
    // central host the fallback would produce.
    expect(config('app.url'))->not->toContain('harbor-cleanup.test')
        ->and(config('app.url'))->not->toContain('ridge-restoration.test');
});
