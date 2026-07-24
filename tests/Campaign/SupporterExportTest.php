<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\Gate;

/*
 * Taking the campaign's list back out of the campaign.
 *
 * Everything here goes through the real route, because the export is a
 * *response* rather than a value: what it produces, what it is called, who is
 * refused and what content type the browser is handed are all properties of the
 * request, and a test calling SupporterExport::writeTo() directly would assert
 * none of them.
 *
 * **The response is streamed, so its body does not exist until it is sent.**
 * $response->getContent() on a StreamedResponse returns false; the rows only
 * appear once the callback runs, which is what streamedContent() forces. A test
 * written the ordinary way would therefore be asserting against `false` rather
 * than against a CSV, and would pass or fail for reasons unrelated to the
 * export. That is the shape of hazard L-26 names -- the technique driving the
 * test deciding what the test can see -- so it is stated here rather than
 * discovered by whoever edits this next.
 *
 * What is *not* guarded here, for the reason the removal tests record: nothing
 * in this suite renders Vue, so a list page that ignored `auth.permissions` and
 * drew the Export control for a Staff operator would pass every assertion
 * below. The policy still refuses the click. That is the frontend hole, and the
 * export adds a second control to it rather than narrowing it.
 */

test('an owner downloads the campaign list as a csv', function (): void {
    Supporter::factory()->create([
        'name' => 'Jean Sacren',
        'given_name' => 'Jean',
        'family_name' => 'Sacren',
        'email' => 'Jean@Example.test',
        'postcode' => '80202',
    ]);

    $response = $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/supporters/export'));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        // The campaign and the day, because a downloads folder is where two
        // campaigns' exports meet.
        ->and($response->headers->get('Content-Disposition'))
        ->toContain($this->campaign->slug.'-supporters-'.now()->toDateString().'.csv');

    $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));

    expect($rows[0])->toBe([
        'Name', 'Given name', 'Family name', 'Email', 'Postcode', 'Subscription status', 'Added on',
    ]);

    // The address is exported with the casing the campaign recorded, not folded
    // -- D-8 stores it exactly as given, and an export that lower-cased it would
    // quietly rewrite the identity on the way out.
    expect($rows[1][0])->toBe('Jean Sacren')
        ->and($rows[1][1])->toBe('Jean')
        ->and($rows[1][2])->toBe('Sacren')
        ->and($rows[1][3])->toBe('Jean@Example.test')
        ->and($rows[1][4])->toBe('80202')
        ->and($rows[1][5])->toBe(SubscriptionStatus::Subscribed->value);
});

test('the export names nobody it was not told about, and hides nobody it was', function (): void {
    // Both halves in one run, because either alone is satisfied by the wrong
    // thing. A supporter with no name must export an *empty* cell rather than a
    // placeholder word -- this module's whole name design rests on never
    // inventing what a source did not say, and "Unknown" in a file is that
    // fabrication one step further from the campaign, where nobody could tell it
    // from a real value. And an unsubscribed supporter must still appear: the
    // status is a column, so an export that filtered them out would misreport
    // the size of the list the campaign holds.
    Supporter::factory()->create([
        'name' => null,
        'given_name' => null,
        'family_name' => null,
        'email' => 'nameless@example.test',
        'postcode' => null,
    ]);

    Supporter::factory()->create([
        'name' => 'Alex Roe',
        'email' => 'alex@example.test',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    $body = $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/supporters/export'))
        ->assertOk()
        ->streamedContent();

    $rows = array_map('str_getcsv', array_filter(explode("\n", $body)));
    $byEmail = collect($rows)->keyBy(3);

    expect($byEmail['nameless@example.test'][0])->toBe('')
        ->and($byEmail['nameless@example.test'][1])->toBe('')
        ->and($byEmail['nameless@example.test'][2])->toBe('')
        ->and($byEmail['nameless@example.test'][4])->toBe('')
        ->and($byEmail)->toHaveKey('alex@example.test')
        ->and($byEmail['alex@example.test'][5])->toBe(SubscriptionStatus::Unsubscribed->value);
});

test('the export carries no database identifier', function (): void {
    // A supporter id is campaign-local and restarts in every campaign -- the
    // same property that made it the wrong thing to build a lock name from at
    // Step 4. Exported it would read as a supporter number and mean nothing
    // outside the one database it came from.
    //
    // Both halves, because the header row alone is the weaker claim: a column
    // could be added to SupporterExport::HEADER under another name and still
    // carry the key. So the row is checked for the value too.
    $supporter = Supporter::factory()->create(['email' => 'ided@example.test']);

    $body = $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/supporters/export'))
        ->assertOk()
        ->streamedContent();

    $rows = array_map('str_getcsv', array_filter(explode("\n", $body)));
    $row = collect($rows)->keyBy(3)->get('ided@example.test');

    expect($rows[0])->toHaveCount(7)
        ->and($rows[0])->not->toContain('Id')
        ->and($rows[0])->not->toContain('ID')
        ->and($row)->not->toContain((string) $supporter->getKey());
});

test('a staff operator may not export the campaign list', function (): void {
    // The deny half. It is evidence only because of the allow above it: a route
    // that did not exist, or one that refused everybody, satisfies this exactly
    // as a working guard does.
    Supporter::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters/export'))
        ->assertForbidden();
});

test('the roles disagree about the export through the route, checked the same way', function (): void {
    // Stated as one assertion so a later edit cannot drop the allow half and
    // leave a deny test guarding nothing -- the same pairing discipline the
    // removal tests use, applied where the request actually arrives.
    Supporter::factory()->create();

    $ownerGot = $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/supporters/export'))
        ->isOk();

    $staffGot = $this->actingAs(User::factory()->create())
        ->get($this->campaignUrl('/supporters/export'))
        ->isOk();

    expect($ownerGot)->toBeTrue()
        ->and($staffGot)->toBeFalse();
});

test('the export refuses an operator whose permission is withdrawn', function (): void {
    // Step 4's idiom, and it is what makes authorize() in export() a line that
    // can fail. Every other deny in this file works because Staff lack the
    // grant; this one withdraws the grant from an *Owner*, so it goes red if
    // the ability is ever re-routed onto a permission both roles hold -- which
    // is exactly the reuse the policy's docblock warns against.
    //
    // The permission is withdrawn rather than the policy stubbed, because a
    // stub would test the stub.
    Gate::define(Permission::ExportSupporters->value, fn (): bool => false);

    $this->actingAs(User::factory()->owner()->create())
        ->get($this->campaignUrl('/supporters/export'))
        ->assertForbidden();
});
