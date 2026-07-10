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

test('a central domain cannot reach a tenant route', function (): void {
    // PreventAccessFromCentralDomains runs ahead of the `web` group and 404s
    // the request before tenancy is ever initialized.
    $this->get('/campaign')->assertNotFound();

    expect(tenancy()->initialized)->toBeFalse();
});

test('signing in is campaign-only and no longer served centrally', function (string $path): void {
    // The observable half of moving authentication into campaign context: these
    // paths used to be served on the central host, because a route with no
    // domain constraint matches any host. They now carry the tenant group, so a
    // central host is turned away before tenancy is ever initialized.
    //
    // Without this, the relocated auth tests would pass whether the routes had
    // been moved or not -- they exercise a campaign host either way.
    $this->get($path)->assertNotFound();

    expect(tenancy()->initialized)->toBeFalse();
})->with([
    'login' => '/login',
    'register' => '/register',
    'dashboard' => '/dashboard',
    'password reset request' => '/forgot-password',
    'profile settings' => '/settings/profile',
]);
