<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\CampaignHostTenancyBootstrapper;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passkeys\Passkeys;
use Tests\Support\Url;

beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', [
        'name' => 'Coastal Trust',
        'domain' => 'coastal-trust.test',
    ]);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $campaign) => $campaign->delete());
});

test('passkeys default to the central host outside campaign context', function (): void {
    // The baseline, and the reason the rest of this file exists: Fortify derives
    // both values from APP_URL, so out of the box a campaign inherits the
    // platform's hostname rather than its own.
    expect(Passkeys::relyingPartyId())->toBe(Url::host((string) config('app.url')));
});

test('a campaign gets its own relying party and origin', function (): void {
    tenancy()->initialize(Tenant::query()->where('slug', 'coastal-trust')->firstOrFail());

    $origins = Passkeys::allowedOrigins();

    expect(Passkeys::relyingPartyId())->toBe('coastal-trust.test')
        ->and($origins)->toHaveCount(1)
        ->and(Url::host($origins[0]))->toBe('coastal-trust.test')
        // The port follows APP_URL, so the origin stays reachable wherever the
        // app is actually served rather than only where it is served today.
        ->and(Url::port($origins[0]))->toBe(Url::port((string) config('app.url')));
});

test('the relying party id is a registrable suffix of every allowed origin', function (): void {
    // The actual WebAuthn requirement, stated as the property rather than as
    // two literals that happen to agree. A browser rejects the ceremony
    // outright when this does not hold, and no test can catch that without a
    // real authenticator -- so it gets asserted here instead.
    tenancy()->initialize(Tenant::query()->where('slug', 'coastal-trust')->firstOrFail());

    $relyingPartyId = Passkeys::relyingPartyId();

    foreach (Passkeys::allowedOrigins() as $origin) {
        $host = (string) Url::host($origin);

        expect($host === $relyingPartyId || str_ends_with($host, '.'.$relyingPartyId))
            ->toBeTrue("Origin [{$origin}] is not covered by relying party id [{$relyingPartyId}].");
    }
});

test('leaving campaign context restores the central relying party and origin', function (): void {
    $centralRelyingParty = Passkeys::relyingPartyId();
    $centralOrigins = Passkeys::allowedOrigins();

    tenancy()->initialize(Tenant::query()->where('slug', 'coastal-trust')->firstOrFail());

    expect(Passkeys::relyingPartyId())->toBe('coastal-trust.test');

    tenancy()->end();

    expect(Passkeys::relyingPartyId())->toBe($centralRelyingParty)
        ->and(Passkeys::allowedOrigins())->toBe($centralOrigins);
});

test('a campaign with no hostname leaves the central relying party alone', function (): void {
    $centralRelyingParty = Passkeys::relyingPartyId();

    $campaign = Tenant::create(['name' => 'Unhosted', 'slug' => 'unhosted']);

    (new CampaignHostTenancyBootstrapper)->bootstrap($campaign);

    expect(Passkeys::relyingPartyId())->toBe($centralRelyingParty);
});
