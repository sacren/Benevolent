<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant as Campaign;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Generates URLs on the active campaign's own hostname.
 *
 * Inside a web request Laravel already derives the URL root from the incoming
 * request, so for those this only restates what was going to happen. It earns
 * its place when there is no request: a queued job falls back to APP_URL, which
 * is the central host, and verification and password-reset mail is queued. Left
 * alone, operators would be emailed links to a host where their own campaign's
 * routes are deliberately unreachable.
 *
 * Only the host and port are ours to set. Laravel replaces a forced root's
 * scheme with the request's own (see UrlGenerator::formatRoot), and console runs
 * -- queue workers included -- build that request from APP_URL, so the scheme is
 * already right in both contexts. The scheme below therefore only keeps the
 * forced root well-formed; the port is the value that genuinely carries, and it
 * comes from APP_URL rather than being declared here so links are right in
 * development (:8042) and production (none) with no second value to keep in
 * agreement.
 */
class UrlTenancyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        if (! $tenant instanceof Campaign) {
            return;
        }

        // Lowest id wins when a campaign has several hostnames -- ours alongside
        // its own branded one, say. Whichever is chosen has to be stable, or a
        // signed link could be generated for one host and validated against
        // another.
        $domain = $tenant->domains()->orderBy('id')->value('domain');

        if (! is_string($domain) || $domain === '') {
            // A campaign can exist before it has a hostname, and central URLs are
            // a better answer than a malformed one.
            return;
        }

        URL::forceRootUrl($this->rootUrlFor($domain));
    }

    public function revert(): void
    {
        // Hands URL generation back to APP_URL and the incoming request.
        URL::forceRootUrl(null);
    }

    private function rootUrlFor(string $domain): string
    {
        $appUrl = (string) config('app.url');

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $port = parse_url($appUrl, PHP_URL_PORT);

        return (is_string($scheme) ? $scheme : 'http').'://'.$domain
            .(is_int($port) ? ':'.$port : '');
    }
}
