<?php

declare(strict_types=1);

use App\Models\Segment;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * The segment module's isolation guarantee where it is actually exercised: over
 * HTTP, by an operator genuinely signed in somewhere.
 *
 * CampaignSegmentIsolationTest makes the same claim through the model, by
 * switching campaigns in-process and asking one query twice. That proves the
 * storage is separate. It cannot prove the *pages* keep them separate, because
 * it issues no request, resolves no campaign from a Host header and never asks
 * the authentication guard who the operator is.
 *
 * **Written as HTTP from the start, which is Phase 2's exit lesson rather than a
 * preference.** That phase's criterion 4 read as satisfied because three
 * *adjacent* proofs -- a model test, a job-level two-campaign test and an HTTP
 * test one route away -- summed to something that looked exactly like the one
 * that was missing. The cost of finding out was paying it at the exit instead
 * of at the step.
 *
 * **This module's hazard has a third shape the two before it did not.** A
 * supporter is reached by a page listing what the campaign owns; a blast is
 * additionally reached by a bare id through route model binding. A segment is
 * reached both of those ways *and* as a query parameter on somebody else's
 * page -- `/supporters?segment=1` and `/supporters/export?segment=1` -- where
 * the id decides which rows another module's surface returns. Segment ids
 * restart at 1 in every campaign, so nothing in that URL says which campaign is
 * meant, and the answer has to come from the Host header.
 *
 * **What makes that worth a test rather than a comment**: the failure is not a
 * 404 anybody notices. It is one campaign's supporter list, or one campaign's
 * exported file, narrowed by another campaign's rule -- and under the export it
 * is a file somebody keeps.
 *
 * **actingAs() cannot ask the identity question and would answer it wrongly.**
 * It binds a User object straight into the guard, so nobody is ever looked up
 * and the cross-campaign lookup is skipped. These tests sign in for real,
 * through the login route, on the campaign's own hostname.
 *
 * Provisions its own campaigns rather than using the campaign harness, for
 * L-10's reason: that trait holds one campaign per file inside a transaction
 * that switching to a second campaign would purge.
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
 * Put one Owner, one segment and two supporters into a campaign.
 *
 * Both campaigns get a supporter in 902 and one in 021, and a segment under the
 * *same name* narrowing to a different one of the two. So the name cannot say
 * which campaign answered and the id cannot either -- only the rows can, which
 * is what makes a leak in either direction visible rather than plausible.
 *
 * Named for what it stocks rather than `stock()`: tests/Pest.php records that a
 * global function cannot be declared twice, and CampaignBlastHttpIsolationTest
 * already holds that name. A second declaration is a fatal redeclare rather
 * than a failing test.
 *
 * @return array{0: User, 1: Segment}
 */
function stockSegments(Tenant $campaign, string $operatorEmail, string $prefix): array
{
    tenancy()->initialize($campaign);

    $operator = User::factory()->owner()->create(['email' => $operatorEmail]);

    Supporter::factory()->create([
        'email' => 'manchester@'.$campaign->slug.'.test',
        'postcode' => '90210',
    ]);
    Supporter::factory()->create([
        'email' => 'edinburgh@'.$campaign->slug.'.test',
        'postcode' => '02139',
    ]);

    $segment = Segment::factory()
        ->narrowedToPostcodes([$prefix])
        ->create(['name' => 'Dockside streets']);

    tenancy()->end();

    return [$operator, $segment];
}

test('a signed-in operator is served their own campaign segments and never another campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockSegments($harbor, 'operator@harbor-cleanup.test', '902');
    [, $ridgeSegment] = stockSegments($ridge, 'operator@ridge-restoration.test', '021');

    // The premise the later tests rest on, asserted rather than assumed: the two
    // segments really do share an id, so a URL carrying a bare id is ambiguous
    // across campaigns. Were ids ever to stop colliding, those tests would keep
    // passing while testing nothing.
    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    // And that the name cannot distinguish them either, which is a fact this
    // module has and the other two do not: `segments.name` is unique *within* a
    // campaign, so two campaigns running in two different districts are both
    // free to call a segment "Dockside streets".
    expect($harborSegment->name)->toBe($ridgeSegment->name);

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    // The positive half, in the same run and through the same session. Without
    // it the negative below is satisfied by a session that never authenticated,
    // by a route that does not exist, and by a page that refuses everybody.
    $this->get('http://harbor-cleanup.test/segments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('segments/Index')
            ->has('segments', 1)
            ->where('segments.0.postcode_prefixes', ['902'])
            ->where('auth.user.email', 'operator@harbor-cleanup.test')
        );

    // The negative. Asserted on the *rule* rather than on the count, because a
    // count of one is also what a page showing the wrong single segment has --
    // and here the name is identical, so the rule is the only thing that differs.
    $this->get('http://harbor-cleanup.test/segments')
        ->assertDontSee('021');
});

test('a segment addressed by an id both campaigns use resolves against the host, never across the two', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockSegments($harbor, 'operator@harbor-cleanup.test', '902');
    [, $ridgeSegment] = stockSegments($ridge, 'operator@ridge-restoration.test', '021');

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('http://harbor-cleanup.test/segments/'.$harborSegment->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('segments/Edit')
            ->where('segment.postcode_prefixes', ['902'])
        )
        ->assertDontSee('021');

    // **The same id, on the other campaign's host, answered as that campaign.**
    // Two in-process artifacts stack here and only one is obvious. The session
    // crosses hostnames because the test client does not model the cookie's host
    // scope; and the guard caches the operator it resolved at login for the life
    // of the process, so a second request never looks anyone up -- something
    // php-fpm cannot do, since every request is its own process. Left in place
    // that makes this request answer as Harbor's operator while rendering
    // Ridge's segment, which reads exactly like a cross-campaign leak and is
    // entirely the harness's doing.
    Auth::forgetGuards();

    $this->get('http://ridge-restoration.test/segments/'.$ridgeSegment->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Answered as the other campaign's own operator, not as the one who
            // signed in. The identity did not travel; only the id did.
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            ->where('segment.postcode_prefixes', ['021'])
        )
        ->assertDontSee('902');

    // And what a real browser gets, since it never sends that cookie here.
    $this->flushSession();
    Auth::forgetGuards();

    // Asserted by substring rather than as a whole URL: the generator appends
    // APP_URL's port, so spelling the address out here would fail on the port
    // rather than on the redirect it is meant to check.
    $this->get('http://ridge-restoration.test/segments/'.$ridgeSegment->getKey().'/edit')
        ->assertRedirectContains('ridge-restoration.test')
        ->assertRedirectContains('/login');
});

test('a narrowed supporter list is narrowed by the host campaign\'s own rule, on both hosts', function (): void {
    // **The claim this step exists to make, and the one no adjacent test
    // reaches.** The segment id arrives as a query parameter on *another
    // module's* page, where it decides which supporters come back. Both
    // campaigns hold a supporter in 902 and one in 021, and segment 1 means
    // something different in each -- so a list narrowed by the wrong campaign's
    // rule returns the wrong person rather than no people, and is visible as a
    // row rather than as a count.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockSegments($harbor, 'operator@harbor-cleanup.test', '902');
    [, $ridgeSegment] = stockSegments($ridge, 'operator@ridge-restoration.test', '021');

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('http://harbor-cleanup.test/supporters?segment='.$harborSegment->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('supporters.total', 1)
            ->where('supporters.data.0.email', 'manchester@harbor-cleanup.test')
        )
        // Neither the other campaign's supporter nor this campaign's Edinburgh
        // one, which are two different mistakes and would both show here.
        ->assertDontSee('edinburgh@harbor-cleanup.test')
        ->assertDontSee('ridge-restoration.test');

    Auth::forgetGuards();

    // The same id, the same path, the other host. The rule that runs is the one
    // stored in the campaign the Host header names.
    $this->get('http://ridge-restoration.test/supporters?segment='.$ridgeSegment->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            ->where('supporters.total', 1)
            ->where('supporters.data.0.email', 'edinburgh@ridge-restoration.test')
        )
        ->assertDontSee('harbor-cleanup.test');
});

test('a narrowed export carries the host campaign\'s own people and nobody else\'s', function (): void {
    // The same claim on the surface where the answer is a file somebody keeps.
    // Owner-only, so both operators are Owners -- a Staff operator is refused
    // earlier and for a different reason, which would satisfy this test without
    // saying anything about isolation.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockSegments($harbor, 'operator@harbor-cleanup.test', '902');
    [, $ridgeSegment] = stockSegments($ridge, 'operator@ridge-restoration.test', '021');

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $harborFile = $this->get('http://harbor-cleanup.test/supporters/export?segment='.$harborSegment->getKey())
        ->assertOk()
        ->streamedContent();

    expect($harborFile)->toContain('manchester@harbor-cleanup.test')
        ->and($harborFile)->not->toContain('edinburgh@harbor-cleanup.test')
        ->and($harborFile)->not->toContain('ridge-restoration.test');

    Auth::forgetGuards();

    $ridgeFile = $this->get('http://ridge-restoration.test/supporters/export?segment='.$ridgeSegment->getKey())
        ->assertOk()
        ->streamedContent();

    expect($ridgeFile)->toContain('edinburgh@ridge-restoration.test')
        ->and($ridgeFile)->not->toContain('harbor-cleanup.test');
});

/**
 * Put one Owner, one district segment and two supporters into a campaign.
 *
 * Both campaigns get a supporter wholly inside MA-07 (`02141`) and one wholly
 * inside CA-37 (`90232`), and a segment under the same name narrowing to the
 * district given. A narrowing run against the wrong campaign's district returns
 * the wrong person rather than nobody, so a leak is visible as a row.
 *
 * @return array{0: User, 1: Segment}
 */
function stockDistrictSegments(Tenant $campaign, string $operatorEmail, string $seat): array
{
    tenancy()->initialize($campaign);

    $operator = User::factory()->owner()->create(['email' => $operatorEmail]);

    Supporter::factory()->create([
        'email' => 'cambridge@'.$campaign->slug.'.test',
        'postcode' => '02141',
    ]);
    Supporter::factory()->create([
        'email' => 'culver-city@'.$campaign->slug.'.test',
        'postcode' => '90232',
    ]);

    $segment = Segment::factory()->inDistrict($seat)->create(['name' => 'Our district']);

    tenancy()->end();

    return [$operator, $segment];
}

test('a list narrowed by a district segment is narrowed by the host campaign\'s own district, on both hosts', function (): void {
    // D-37's segment, reached the way the prefix segment above is: as a query
    // parameter on the supporter list, by an id both campaigns use. The district
    // is stored per campaign and the map it is read against is shared, so a leak
    // would come from the segment and not from the data.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockDistrictSegments($harbor, 'operator@harbor-cleanup.test', 'MA-07');
    [, $ridgeSegment] = stockDistrictSegments($ridge, 'operator@ridge-restoration.test', 'CA-37');

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey())
        ->and($harborSegment->name)->toBe($ridgeSegment->name);

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('http://harbor-cleanup.test/supporters?segment='.$harborSegment->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('supporters.total', 1)
            ->where('supporters.data.0.email', 'cambridge@harbor-cleanup.test')
            ->where('districts.narrowing.seat', 'MA-07')
        )
        ->assertDontSee('culver-city@harbor-cleanup.test')
        ->assertDontSee('ridge-restoration.test');

    Auth::forgetGuards();

    $this->get('http://ridge-restoration.test/supporters?segment='.$ridgeSegment->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            ->where('supporters.total', 1)
            ->where('supporters.data.0.email', 'culver-city@ridge-restoration.test')
            ->where('districts.narrowing.seat', 'CA-37')
        )
        ->assertDontSee('cambridge@ridge-restoration.test')
        ->assertDontSee('harbor-cleanup.test');
});

test('an export narrowed by a district segment carries the host campaign\'s own people and nobody else\'s', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockDistrictSegments($harbor, 'operator@harbor-cleanup.test', 'MA-07');
    [, $ridgeSegment] = stockDistrictSegments($ridge, 'operator@ridge-restoration.test', 'CA-37');

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $harborFile = $this->get('http://harbor-cleanup.test/supporters/export?segment='.$harborSegment->getKey())
        ->assertOk()
        ->streamedContent();

    expect($harborFile)->toContain('cambridge@harbor-cleanup.test')
        ->and($harborFile)->not->toContain('culver-city@harbor-cleanup.test')
        ->and($harborFile)->not->toContain('ridge-restoration.test');

    Auth::forgetGuards();

    $ridgeFile = $this->get('http://ridge-restoration.test/supporters/export?segment='.$ridgeSegment->getKey())
        ->assertOk()
        ->streamedContent();

    expect($ridgeFile)->toContain('culver-city@ridge-restoration.test')
        ->and($ridgeFile)->not->toContain('cambridge@ridge-restoration.test')
        ->and($ridgeFile)->not->toContain('harbor-cleanup.test');
});

test('two campaigns naming the same district read it against the same map, each over its own people', function (): void {
    // Exit criterion 4 both ways round for this module: the district data is
    // shared reference data, so two campaigns naming MA-07 are told the same
    // thing about it, and each is shown only its own supporters in it.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockDistrictSegments($harbor, 'operator@harbor-cleanup.test', 'MA-07');
    [, $ridgeSegment] = stockDistrictSegments($ridge, 'operator@ridge-restoration.test', 'MA-07');

    $this->post('http://harbor-cleanup.test/login', [
        'email' => 'operator@harbor-cleanup.test',
        'password' => 'password',
    ])->assertRedirect();

    $sameMap = ['district' => 'MA-07', 'seat' => 'MA-07', 'wholly' => 17, 'crossing' => 26];

    $this->get('http://harbor-cleanup.test/supporters?segment='.$harborSegment->getKey())
        ->assertInertia(fn ($page) => $page
            ->where('districts.narrowing', $sameMap)
            ->where('supporters.data.0.email', 'cambridge@harbor-cleanup.test')
            ->where('supporters.total', 1)
        );

    Auth::forgetGuards();

    $this->get('http://ridge-restoration.test/supporters?segment='.$ridgeSegment->getKey())
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            ->where('districts.narrowing', $sameMap)
            ->where('supporters.data.0.email', 'cambridge@ridge-restoration.test')
            ->where('supporters.total', 1)
        );
});
