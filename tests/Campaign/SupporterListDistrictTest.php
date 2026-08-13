<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\User;

/*
 * The district column on the supporter list, as the server hands it to the page.
 *
 * What may be claimed from a ZIP is decided and guarded in
 * tests/Feature/DistrictClaimTest.php. This file asks what reaches the page:
 * that each row arrives with the answer for its own supporter, that the map the
 * answers were read against arrives with them, and that only the rows on the
 * page are answered at all. Whether the page then shows a split ZIP's district
 * as somebody's own is a question only a browser can answer, and
 * tests/Browser/SupporterListPageTest.php answers it.
 */

test('each row arrives with what may be said about its supporter\'s district, and the map it was read against', function (): void {
    $placed = Supporter::factory()->create(['postcode' => '90232']);
    $split = Supporter::factory()->create(['postcode' => '90210']);
    $unmapped = Supporter::factory()->create(['postcode' => '73301']);
    $mangled = Supporter::factory()->create(['postcode' => '2139']);
    $blank = Supporter::factory()->create(['postcode' => null]);

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('supporters/Index')
            // Formatted by the server, so the page shows exactly this.
            ->where('districts.congress', '119th')
            ->where('districts.publishedOn', 'October 24, 2024')
            // This harness's campaign records no seat, so nothing is compared
            // with one. The comparison is proven with two campaigns holding
            // different seats, in tests/Tenancy/CampaignDistrictHttpIsolationTest.php.
            ->where('districts.seat', null)
            ->where("districts.bySupporter.{$placed->getKey()}", [
                'answer' => 'placed',
                'zip' => '90232',
                'touching' => ['CA-37'],
                'claimed' => 'CA-37',
                'mayHaveLostLeadingZero' => false,
                'seatStanding' => null,
            ])
            // The districts it might be in, and no claim among them.
            ->where("districts.bySupporter.{$split->getKey()}", [
                'answer' => 'split',
                'zip' => '90210',
                'touching' => ['CA-30', 'CA-32', 'CA-36'],
                'claimed' => null,
                'mayHaveLostLeadingZero' => false,
                'seatStanding' => null,
            ])
            ->where("districts.bySupporter.{$unmapped->getKey()}.answer", 'unmapped')
            ->where("districts.bySupporter.{$mangled->getKey()}.answer", 'malformed')
            ->where("districts.bySupporter.{$mangled->getKey()}.mayHaveLostLeadingZero", true)
            ->where("districts.bySupporter.{$blank->getKey()}.answer", 'missing')
        );
});

test('only the rows on the page are answered, so the cost does not grow with the list', function (): void {
    // One more than a page. The district answers are worked out per request
    // (D-35), so answering the whole list would put a lookup per supporter on
    // the one page that pages precisely because the whole list did not fit.
    Supporter::factory()->count(51)->create(['postcode' => '90232']);

    $response = $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters'))
        ->assertOk();

    $props = $response->viewData('page')['props'];
    $onThePage = array_column($props['supporters']['data'], 'id');

    // Keyed by the rows the page carries and by nothing else.
    expect(array_keys($props['districts']['bySupporter']))->toEqualCanonicalizing($onThePage)
        ->and($onThePage)->toHaveCount(50);
});
