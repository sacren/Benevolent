<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * District answers over HTTP, in two campaigns at once: the one place in this
 * module where campaigns must agree, and the one where they must not.
 *
 * **Exit criterion 4 is asked both ways here, because this module holds both
 * kinds of data.** The district relation is public reference data that ships
 * with the code (D-34), so two campaigns asking about the same ZIP code must be
 * told the same district -- tests/Tenancy/CampaignDistrictDataTest.php proves
 * that of the reader, and this proves it of the page. The seat each campaign is
 * running for is that campaign's own (D-40), so the same supporter's standing
 * must come out differently in each, against each campaign's own seat.
 *
 * Signed in for real, through the login route on each campaign's hostname, for
 * the reason tests/Tenancy/CampaignSupporterHttpIsolationTest.php gives:
 * actingAs() binds a user straight into the guard and skips the cross-campaign
 * lookup that is the whole hazard. Provisions its own campaigns (L-10).
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * An Owner and two supporters in a campaign -- one in Massachusetts' 7th, one
 * in California's 37th -- so each campaign's seat has one of its own and one
 * from elsewhere.
 *
 * @return array{inMassachusetts: int, inCalifornia: int}
 */
function supportersInTwoSeats(Tenant $campaign, string $operatorEmail): array
{
    tenancy()->initialize($campaign);

    User::factory()->owner()->create(['email' => $operatorEmail]);
    $inMassachusetts = Supporter::factory()->create(['postcode' => '02141']);
    $inCalifornia = Supporter::factory()->create(['postcode' => '90232']);

    tenancy()->end();

    return ['inMassachusetts' => $inMassachusetts->getKey(), 'inCalifornia' => $inCalifornia->getKey()];
}

/**
 * The district props as the browser receives them: through JSON, which is where
 * each DistrictClaim becomes the fields a page reads.
 *
 * @return array<string, mixed>
 */
function sentToTheBrowser(TestResponse $response): array
{
    return json_decode((string) json_encode($response->viewData('page')['props']['districts']), true);
}

test('two campaigns are told the same districts, and each supporter\'s standing against its own campaign\'s seat', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    $harborRows = supportersInTwoSeats($harbor, 'operator@harbor-cleanup.test');
    $ridgeRows = supportersInTwoSeats($ridge, 'operator@ridge-restoration.test');

    // Ids restart at 1 in every campaign, so the two campaigns' supporters share
    // ids and a page answering from the wrong campaign would look right by id.
    // Stated, so the collision this test depends on cannot quietly go away.
    expect($harborRows)->toBe($ridgeRows);

    Artisan::call('campaign:seat', ['slug' => 'harbor-cleanup', 'seat' => 'MA-07']);
    Artisan::call('campaign:seat', ['slug' => 'ridge-restoration', 'seat' => 'CA-37']);

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $harborPage = sentToTheBrowser($this->get('http://harbor-cleanup.test/supporters')->assertOk());

    // L-13's discipline for a second request in one process: end the campaign
    // and forget the guard, as a new php-fpm process would.
    tenancy()->end();
    Auth::forgetGuards();

    $this->post('http://ridge-restoration.test/login', [
        'email' => 'operator@ridge-restoration.test',
        'password' => 'password',
    ])->assertRedirect();

    $ridgePage = sentToTheBrowser($this->get('http://ridge-restoration.test/supporters')->assertOk());

    $standing = fn (array $page, int $id): ?string => $page['bySupporter'][$id]['seatStanding'];
    $claimed = fn (array $page, int $id): ?string => $page['bySupporter'][$id]['claimed'];

    $massachusetts = $harborRows['inMassachusetts'];
    $california = $harborRows['inCalifornia'];

    // Shared: the same ZIP codes are the same districts in both campaigns.
    expect([$claimed($harborPage, $massachusetts), $claimed($harborPage, $california)])->toBe(['MA-07', 'CA-37'])
        ->and([$claimed($ridgePage, $massachusetts), $claimed($ridgePage, $california)])->toBe(['MA-07', 'CA-37'])

        // Each campaign's own: its seat, and where the same two supporters stand
        // against it -- opposite ways round in the two campaigns.
        ->and([$harborPage['seat'], $standing($harborPage, $massachusetts), $standing($harborPage, $california)])
        ->toBe(['MA-07', 'in', 'not'])
        ->and([$ridgePage['seat'], $standing($ridgePage, $massachusetts), $standing($ridgePage, $california)])
        ->toBe(['CA-37', 'not', 'in']);
});
