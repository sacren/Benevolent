<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BuiltAssets;
use Tests\Support\Url;

/**
 * A campaign's pages must load their scripts and styles from the built assets
 * in public/build, on the campaign's own host.
 *
 * Tenancy's filesystem bootstrapper can make asset() campaign-aware, pointing
 * it at a route that serves files from the campaign's storage directory.
 * Laravel's Vite helper builds every script and stylesheet URL through asset(),
 * so with that on, each campaign page asked for /tenancy/assets/build/..., got
 * an HTML error page back, and rendered blank. Found on the first deployed
 * campaign (Phase 7 Step 3); central pages were unaffected.
 *
 * The browser suite cannot see this, and that is why the guard lives here. The
 * browser plugin calls useAssetOrigin() when it starts its server, overwriting
 * the asset root the bootstrapper had set while the test entered the campaign,
 * and every request then re-enters that same campaign, which tenancy treats as
 * already done. So the pages a browser test opens always get correct asset URLs.
 * These requests enter no campaign first, which is what production does.
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    BuiltAssets::serveFromBuild();

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $campaign) => $campaign->delete());
});

/**
 * The script, module-preload and stylesheet URLs a page's HTML asks for.
 *
 * @return list<string>
 */
function pageAssetUrls(string $html): array
{
    preg_match_all('/(?:src|href)="([^"]+\.(?:js|css))"/', $html, $matches);

    return $matches[1];
}

test('each campaign page loads the built assets from its own host', function (string $host): void {
    expect(tenancy()->initialized)->toBeFalse();

    $urls = pageAssetUrls((string) $this->get('http://'.$host.'/login')->getContent());

    // Without this, a page that lists no assets at all would pass every
    // assertion below.
    expect($urls)->not->toBeEmpty();

    foreach ($urls as $assetUrl) {
        expect($assetUrl)->not->toContain('/tenancy/assets/')
            ->and(Url::host($assetUrl))->toBe($host)
            ->and((string) parse_url($assetUrl, PHP_URL_PATH))->toStartWith('/build/');
    }
})->with(['harbor-cleanup.test', 'ridge-restoration.test']);
