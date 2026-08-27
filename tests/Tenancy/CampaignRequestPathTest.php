<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\Support\Url;

/**
 * The request path as the deployed server receives it: straight from the
 * client, with no proxy in front (Phase 7, D-63 -- Cloudflare's DNS-only
 * records answer with the server's own address, and TLS ends on the server).
 *
 * With no proxy, every X-Forwarded-* header was written by the client. So the
 * whole of this file is one claim from two sides: the Host a request arrives
 * on decides the campaign, the scheme and the host of every link it is sent
 * back, and nothing a client adds beside it moves any of them. Trusting every
 * caller as a proxy -- a single `trustProxies(at: '*')` -- was measured at Step
 * 2 to hand all of it to the client: the campaign, the scheme, and the address
 * every per-address throttle is keyed on.
 *
 * Two campaigns and the central host, always. With one campaign, "the campaign
 * the Host names" and "a campaign" are the same row, and a forged header
 * pointing somewhere else has nowhere to point.
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Watch', 'domain' => 'harbor-watch.test']);
    Artisan::call('campaign:create', ['name' => 'Meadow Fund', 'domain' => 'meadow-fund.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $campaign) => $campaign->delete());
});

/**
 * The host a request names, with the port APP_URL carries.
 *
 * Derived from APP_URL rather than typed, because which host is central is
 * decided there (config/tenancy.php), and a literal would pass here while the
 * deployed central host went unguarded.
 */
function requestPathHost(string $who): string
{
    if ($who === 'central') {
        $appUrl = (string) config('app.url');
        $port = Url::port($appUrl);

        return Url::host($appUrl).(is_int($port) ? ':'.$port : '');
    }

    return $who.'.test';
}

/**
 * Ask for the dashboard signed out, which every host answers with a redirect
 * built by the URL generator: a campaign sends the visitor to its own sign-in,
 * the central host to the signpost. The Location header is therefore a link
 * the application generated, not one the test composed.
 */
function generatedRedirect(TestResponse $response): string
{
    $response->assertRedirect();

    return (string) $response->headers->get('Location');
}

/**
 * Which campaign the request resolved, or null for none.
 */
function resolvedCampaign(): ?string
{
    return tenancy()->initialized ? (string) tenant('slug') : null;
}

test('each host resolves to itself and is sent links for itself', function (string $who, string $scheme): void {
    $location = generatedRedirect($this->get($scheme.'://'.requestPathHost($who).'/dashboard'));

    expect(resolvedCampaign())->toBe($who === 'central' ? null : $who)
        ->and(Url::host($location))->toBe(Url::host('http://'.requestPathHost($who)))
        ->and(Url::scheme($location))->toBe($scheme)
        ->and(parse_url($location, PHP_URL_PATH))->toBe($who === 'central' ? '/campaign-sign-in' : '/login');
})->with([
    'the central host' => 'central',
    'one campaign' => 'harbor-watch',
    'another campaign' => 'meadow-fund',
])->with(['http', 'https']);

test('forwarded headers from a client choose nothing', function (string $who, string $claimed): void {
    // The claimed host is a campaign that exists, so a request that honoured
    // the header would have somewhere real to go -- the wrong row is in the
    // fixture, which is what lets this see a wrong choice at all.
    expect(Tenant::query()->where('slug', $claimed)->exists())->toBeTrue();

    $appUrlPort = Url::port((string) config('app.url'));

    $response = $this->withHeaders([
        'X-Forwarded-Host' => requestPathHost($claimed),
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '4443',
        'X-Forwarded-For' => '203.0.113.9',
    ])->get('http://'.requestPathHost($who).'/dashboard');

    $location = generatedRedirect($response);

    expect(resolvedCampaign())->toBe($who === 'central' ? null : $who)
        ->and(Url::host($location))->toBe(Url::host('http://'.requestPathHost($who)))
        ->and(Url::scheme($location))->toBe('http')
        ->and(Url::port($location))->toBe($appUrlPort)
        // The address every per-address throttle is keyed on: Fortify's
        // sign-in, two-factor, password-reset and passkey limiters, and the
        // invitation and unsubscribe ones, all read $request->ip().
        ->and(app('request')->ip())->toBe('127.0.0.1');
})->with([
    'the central host claiming a campaign' => ['central', 'harbor-watch'],
    'one campaign claiming the other' => ['harbor-watch', 'meadow-fund'],
    'the other claiming the first' => ['meadow-fund', 'harbor-watch'],
]);

test('no caller is trusted as a proxy', function (): void {
    // The configuration behind the test above. Nothing in this shape sits in
    // front of the application, so the list of trusted proxies is empty from
    // both of the places the framework reads it: bootstrap/app.php's
    // trustProxies(at: ...) and config('trustedproxy.proxies'). If Cloudflare's
    // proxy is ever switched on, that is a new decision and this is where it
    // shows up (Phase 7 Step 1, host check 2).
    $trusted = (fn (): mixed => $this->proxies())->call(app(TrustProxies::class));

    expect($trusted)->toBeEmpty()
        ->and(config('trustedproxy.proxies'))->toBeNull();
});
