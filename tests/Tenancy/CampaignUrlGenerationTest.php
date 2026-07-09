<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\UrlTenancyBootstrapper;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Url;

/**
 * The campaign these tests generate URLs for.
 */
function urlProbeCampaign(): Tenant
{
    return Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
}

beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', [
        'name' => 'Harbor Cleanup',
        'domain' => 'harbor-cleanup.test',
    ]);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $campaign) => $campaign->delete());
});

test('URLs generated in campaign context use the campaign hostname', function (): void {
    $appUrl = (string) config('app.url');

    // No request is involved here, and that is the case that was broken: outside
    // a request, URL generation falls back to APP_URL -- the central host.
    expect(Url::host(url('/')))->toBe(Url::host($appUrl));

    tenancy()->initialize(urlProbeCampaign());

    expect(Url::host(url('/')))->toBe('harbor-cleanup.test')
        ->and(Url::host(route('home')))->toBe('harbor-cleanup.test')
        // The port still follows APP_URL, so links stay reachable wherever the
        // app is actually served.
        ->and(Url::port(url('/')))->toBe(Url::port($appUrl));
});

test('queued password reset mail links to the campaign hostname', function (): void {
    // The regression this all exists for. Reset and verification mail is queued,
    // so it is composed outside any request; a link to the central host would
    // send the operator somewhere their campaign's routes are unreachable.
    tenancy()->initialize(urlProbeCampaign());

    $operator = User::factory()->create();

    $mail = (new ResetPassword('reset-token'))->toMail($operator);

    expect(Url::host((string) $mail->actionUrl))->toBe('harbor-cleanup.test');
});

test('ending campaign context hands URL generation back to the central host', function (): void {
    $centralHost = Url::host((string) config('app.url'));

    tenancy()->initialize(urlProbeCampaign());

    expect(Url::host(url('/')))->toBe('harbor-cleanup.test');

    tenancy()->end();

    expect(Url::host(url('/')))->toBe($centralHost);
});

test('the port comes from APP_URL rather than being hardcoded', function (): void {
    // The guard L-8 asks for: a production-shaped APP_URL must not keep producing
    // development-shaped links. A hardcoded :8042 fails this. The bootstrapper is
    // called directly because re-initializing tenancy here would reconnect the
    // campaign's database mid-test.
    //
    // The scheme is deliberately not asserted: Laravel overwrites a forced root's
    // scheme with the request's, and in console and queue runs it builds that
    // request from APP_URL, so the scheme is never this class's to decide.
    config(['app.url' => 'https://nucleus.example']);

    (new UrlTenancyBootstrapper)->bootstrap(urlProbeCampaign());

    expect(Url::host(url('/')))->toBe('harbor-cleanup.test')
        ->and(Url::port(url('/')))->toBeNull();
});

test('a campaign with no hostname leaves URL generation central', function (): void {
    $centralHost = Url::host((string) config('app.url'));

    $campaign = Tenant::create(['name' => 'No Domain Yet', 'slug' => 'no-domain-yet']);

    (new UrlTenancyBootstrapper)->bootstrap($campaign);

    expect(Url::host(url('/')))->toBe($centralHost);
});

test('the bootstrapper is registered so tenancy applies it automatically', function (): void {
    expect(config('tenancy.bootstrappers'))->toContain(UrlTenancyBootstrapper::class);
});
