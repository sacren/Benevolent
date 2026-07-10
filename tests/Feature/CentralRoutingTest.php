<?php

declare(strict_types=1);

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

test('a central host cannot reach campaign routes', function (string $path): void {
    // PreventAccessFromCentralDomains runs ahead of the web group and turns the
    // request away before tenancy is ever initialized. Without this, moving the
    // routes into campaign context and forgetting to move them would look
    // identical, because the relocated tests exercise a campaign host either
    // way.
    //
    // Every path below is an authentication or settings route, for the plain
    // reason that those are currently the only campaign routes there are. The
    // guard is not specific to them: any campaign route added later is covered
    // by the same middleware and belongs in this list.
    $this->get($path)->assertNotFound();

    expect(tenancy()->initialized)->toBeFalse();
})->with([
    'login' => '/login',
    'register' => '/register',
    'dashboard' => '/dashboard',
    'password reset request' => '/forgot-password',
    'profile settings' => '/settings/profile',
]);
