<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant as Campaign;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Makes the application answer as the active campaign's own hostname.
 *
 * Two things key off that hostname, and both are wrong by default because both
 * derive from APP_URL, which is the central host.
 *
 * Generated URLs. Inside a web request Laravel already takes the root from the
 * incoming request, so there this only restates what was going to happen. It
 * earns its place when there is no request: a queued job falls back to APP_URL,
 * and verification and password-reset mail is queued, so operators would be
 * emailed links to a host where their own campaign's routes are deliberately
 * unreachable.
 *
 * Passkeys. A WebAuthn relying party id must be a registrable suffix of the
 * origin the ceremony runs on, and the allowed origins must contain that
 * origin. Left central, every passkey enrolment and sign-in on a campaign
 * hostname is rejected by the browser -- and no test notices, because
 * exercising one needs a real authenticator.
 *
 * Only the host and port of a generated URL are ours to set. Laravel replaces a
 * forced root's scheme with the request's own (see UrlGenerator::formatRoot),
 * and console runs -- queue workers included -- build that request from
 * APP_URL, so the scheme is already right in both contexts. The scheme below
 * only keeps the forced root well-formed; the port is what genuinely carries,
 * and it comes from APP_URL rather than being declared here so links are right
 * in development (:8042) and production (none) with no second value to keep in
 * agreement.
 */
class CampaignHostTenancyBootstrapper implements TenancyBootstrapper
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
            // A campaign can exist before it has a hostname, and central values
            // are a better answer than malformed ones.
            return;
        }

        $rootUrl = $this->rootUrlFor($domain);

        URL::forceRootUrl($rootUrl);

        // Fortify copies these out of its own config once at boot; the passkey
        // package then reads them on each call, which is what makes steering
        // them per request possible at all.
        config([
            'passkeys.relying_party_id' => $domain,
            'passkeys.allowed_origins' => [$rootUrl],
        ]);
    }

    public function revert(): void
    {
        // Hands URL generation back to APP_URL and the incoming request.
        URL::forceRootUrl(null);

        // Recomputed rather than remembered: config/fortify.php still holds the
        // central values, so restoring from there cannot drift, and a second
        // bootstrap without an intervening revert cannot capture one campaign's
        // values as though they were the central ones.
        config([
            'passkeys.relying_party_id' => config('fortify.passkeys.relying_party_id'),
            'passkeys.allowed_origins' => config('fortify.passkeys.allowed_origins'),
        ]);
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
