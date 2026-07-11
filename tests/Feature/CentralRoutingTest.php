<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('the central host is derived from APP_URL in every environment', function (): void {
    // The one invariant that must hold wherever this deploys: the host we generate
    // our own URLs for is never treated as a campaign. Hardcoding a development
    // hostname here would satisfy the suite locally and silently disarm the guard
    // in production, so assert the derivation rather than a literal.
    $centralHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    expect(config('tenancy.central_domains'))->toContain($centralHost);
});

test('a request to a central domain stays central and the home route still resolves', function (): void {
    // Learning L-2 regression guard: registering tenant routes must never
    // replace the central `home` route ('/') or its name lookup.
    expect(route('home'))->toBe(url('/'));

    $this->get('/')->assertOk();

    expect(tenancy()->initialized)->toBeFalse();
});

test('a central host asking for a campaign route is signposted, not refused', function (string $path): void {
    // PreventAccessFromCentralDomains runs ahead of the web group and turns the
    // request away before tenancy is ever initialized. Without this, moving the
    // routes into campaign context and forgetting to move them would look
    // identical, because the relocated tests exercise a campaign host either
    // way.
    //
    // This asserted a 404 until the signpost existed. The refusal is unchanged —
    // no campaign route is served here, and tenancy still never initializes —
    // but a visitor who arrives by following the Welcome page's own "Log in"
    // link is told where sign-in actually lives instead of hitting a dead end.
    //
    // Every path below is an authentication or settings route, for the plain
    // reason that those are currently the only campaign routes there are. The
    // guard is not specific to them: any campaign route added later is covered
    // by the same middleware and belongs in this list.
    $this->get($path)->assertRedirect(route('campaign-sign-in'));

    expect(tenancy()->initialized)->toBeFalse();
})->with([
    'login' => '/login',
    'register' => '/register',
    'dashboard' => '/dashboard',
    'password reset request' => '/forgot-password',
    'profile settings' => '/settings/profile',
]);

test('the signpost is served centrally, so the redirect cannot loop', function (): void {
    // The page the redirect points at is a plain central route: it carries the
    // web group and not the tenant group, so PreventAccessFromCentralDomains
    // never runs on it. If it ever moved behind that middleware, this request
    // would redirect to itself and the assertion below would fail rather than
    // hang.
    $this->get(route('campaign-sign-in'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('CampaignSignIn'));

    expect(tenancy()->initialized)->toBeFalse();
});
