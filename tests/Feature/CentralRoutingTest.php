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
