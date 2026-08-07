<?php

declare(strict_types=1);

use App\Models\Blast;
use App\Models\Segment;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * The path Step 4 created: a blast in one campaign pointing at a segment, over
 * HTTP, with two campaigns whose segments share an id.
 *
 * **This is a cross-*module* claim and no existing file makes it.**
 * CampaignSegmentHttpIsolationTest proves a segment id resolves against the
 * Host header on the segment pages and on the supporter list. CampaignBlastHttp-
 * IsolationTest proves a blast id does the same on the blast pages. Neither
 * touches the join between them, and until Step 4 there was no join to touch --
 * so this is exactly the shape Phase 2's exit found unmet, where adjacent
 * proofs summed to something that looked like the missing one.
 *
 * **What is new, and what makes it worth a file rather than a case.** A blast's
 * aim is now a foreign key rather than a value on its own row, so answering
 * "who would this blast reach" means following a pointer to a second table
 * before any supporter is selected. Segment ids restart at 1 in every campaign
 * (L-27), so the number in `blasts.segment_id` says nothing about which
 * campaign's rule is meant; the answer has to come from the connection tenancy
 * opened, and it has to come out right in a *worker's* reading of it as much as
 * a page's.
 *
 * **The failure this guards against is not a 404.** It is a message going to
 * the wrong people -- one campaign's blast narrowed by another campaign's
 * postcodes -- and there is no unsending. That is why the assertions below are
 * on *who* and *how many*, never on a status code.
 *
 * **actingAs() would answer the identity question wrongly**, binding a User
 * straight into the guard so nobody is ever looked up. These tests sign in for
 * real, through the login route, on the campaign's own hostname.
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
 * Put one Owner, one segment and an uneven supporter list into a campaign.
 *
 * **The two campaigns get the same segment name and different rules, and
 * deliberately different-sized audiences.** The shared name means the name
 * cannot say which campaign answered; the shared id means the URL cannot
 * either. What is left is the rule and the people it selects -- and the sizes
 * are made unequal on purpose, because two campaigns whose aims happen to reach
 * the same number of people would let a leak pass as a match.
 *
 * Named for the pair it stocks rather than `stock()` or `stockSegments()`:
 * tests/Pest.php records that a global function cannot be declared twice and
 * both of those names are taken by the two files this one sits between. A
 * second declaration is a fatal redeclare rather than a failing test.
 *
 * @param  list<string>  $postcodes  one supporter per entry, subscribed
 * @return array{0: User, 1: Segment}
 */
function stockBlastSegments(Tenant $campaign, string $operatorEmail, string $prefix, array $postcodes): array
{
    tenancy()->initialize($campaign);

    $operator = User::factory()->owner()->create(['email' => $operatorEmail]);

    foreach ($postcodes as $index => $postcode) {
        Supporter::factory()->create([
            'email' => 'supporter'.$index.'@'.$campaign->slug.'.test',
            'postcode' => $postcode,
        ]);
    }

    $segment = Segment::factory()
        ->narrowedToPostcodes([$prefix])
        ->create(['name' => 'Dockside streets']);

    tenancy()->end();

    return [$operator, $segment];
}

/**
 * Sign an operator in for real, on their campaign's own hostname.
 */
function signInAt(string $host, string $email): void
{
    Auth::forgetGuards();

    test()->post('http://'.$host.'/login', [
        'email' => $email,
        'password' => 'password',
    ])->assertRedirect();
}

test('the compose form offers the host campaign\'s segments and never another campaign\'s', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockBlastSegments($harbor, 'operator@harbor-cleanup.test', 'M15', ['M15 6BH', 'M15 9AA', 'EH8 9YL']);
    [, $ridgeSegment] = stockBlastSegments($ridge, 'operator@ridge-restoration.test', 'EH8', ['M15 6BH', 'EH8 9YL', 'EH8 1AB', 'EH8 2CD']);

    // The premise the rest of the file rests on, asserted rather than assumed.
    // Were the ids ever to stop colliding, every test below would keep passing
    // while testing nothing.
    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey())
        ->and($harborSegment->name)->toBe($ridgeSegment->name);

    signInAt('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    // The positive half, in the same run and through the same session: without
    // it the negative is satisfied by a page that offers nobody anything.
    $this->get('http://harbor-cleanup.test/blasts/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('blasts/Create')
            ->has('segments', 1)
            ->where('segments.0.postcode_prefixes', ['M15'])
            ->where('auth.user.email', 'operator@harbor-cleanup.test')
        )
        // Asserted on the rule rather than the name, because the name is
        // identical in both campaigns and would prove nothing.
        ->assertDontSee('EH8');

    signInAt('ridge-restoration.test', 'operator@ridge-restoration.test');

    $this->get('http://ridge-restoration.test/blasts/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('segments', 1)
            ->where('segments.0.postcode_prefixes', ['EH8'])
            ->where('auth.user.email', 'operator@ridge-restoration.test')
        )
        ->assertDontSee('M15');
});

test('a blast aimed by an id both campaigns use reaches the host campaign\'s own people', function (): void {
    // **The claim this file exists to make.** The id in the form is the same
    // number on both hosts and means a different rule in each, so a blast
    // aimed with it reaches a different set of people depending on which
    // campaign answered -- and the sets are different sizes on purpose, so a
    // leak shows as a wrong number rather than as a coincidence.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockBlastSegments($harbor, 'operator@harbor-cleanup.test', 'M15', ['M15 6BH', 'M15 9AA', 'EH8 9YL']);
    [, $ridgeSegment] = stockBlastSegments($ridge, 'operator@ridge-restoration.test', 'EH8', ['M15 6BH', 'EH8 9YL', 'EH8 1AB', 'EH8 2CD']);

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    signInAt('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    $this->post('http://harbor-cleanup.test/blasts', [
        'subject' => 'Dockside works begin',
        'body' => 'The consultation closes on Friday.',
        'segment_id' => (string) $harborSegment->getKey(),
    ])->assertRedirect();

    tenancy()->initialize($harbor);
    $harborBlast = Blast::query()->sole();
    tenancy()->end();

    // Two supporters in M15, not the three this campaign holds and not the
    // three the *other* campaign's rule would have selected from its own list.
    $this->get('http://harbor-cleanup.test/blasts/'.$harborBlast->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('blast.segment_id', $harborSegment->getKey())
            ->where('audienceSize', 2)
        );

    signInAt('ridge-restoration.test', 'operator@ridge-restoration.test');

    $this->post('http://ridge-restoration.test/blasts', [
        'subject' => 'Ridge path closure',
        'body' => 'The path is shut for six weeks.',
        // The same number, submitted on the other host.
        'segment_id' => (string) $ridgeSegment->getKey(),
    ])->assertRedirect();

    tenancy()->initialize($ridge);
    $ridgeBlast = Blast::query()->sole();
    tenancy()->end();

    $this->get('http://ridge-restoration.test/blasts/'.$ridgeBlast->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'operator@ridge-restoration.test')
            // Three in EH8. Different from the two above, so this cannot be
            // satisfied by an audience that resolved the wrong campaign's rule.
            ->where('audienceSize', 3)
        );
});

test('the blast list names the host campaign\'s own narrowing, never the other\'s', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockBlastSegments($harbor, 'operator@harbor-cleanup.test', 'M15', ['M15 6BH', 'M15 9AA', 'EH8 9YL']);
    [, $ridgeSegment] = stockBlastSegments($ridge, 'operator@ridge-restoration.test', 'EH8', ['M15 6BH', 'EH8 9YL', 'EH8 1AB', 'EH8 2CD']);

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    tenancy()->initialize($harbor);
    Blast::factory()->aimedAtSegment($harborSegment)->create(['subject' => 'Dockside works begin']);
    tenancy()->end();

    tenancy()->initialize($ridge);
    Blast::factory()->aimedAtSegment($ridgeSegment)->create(['subject' => 'Ridge path closure']);
    tenancy()->end();

    signInAt('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    // The eager load follows the pointer into a second table, which is the
    // step this page did not take before Step 4 -- and the one place a
    // relation resolved against the wrong connection would show as a name.
    $this->get('http://harbor-cleanup.test/blasts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('blasts', 1)
            ->where('blasts.0.segment.postcode_prefixes', ['M15'])
        )
        ->assertDontSee('EH8')
        ->assertDontSee('Ridge path closure');

    signInAt('ridge-restoration.test', 'operator@ridge-restoration.test');

    $this->get('http://ridge-restoration.test/blasts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('blasts', 1)
            ->where('blasts.0.segment.postcode_prefixes', ['EH8'])
        )
        ->assertDontSee('M15')
        ->assertDontSee('Dockside works begin');
});

test('a segment one campaign has a blast aimed at is still removable in the other', function (): void {
    // **The deletion refusal is per campaign, and nothing else in the suite
    // says so.** Both campaigns hold segment 1; only Harbor has a blast aimed
    // at it. A refusal computed against the wrong connection would either
    // block Ridge from removing a segment nothing points at, or let Harbor
    // remove one that a blast names -- and the second is the direction that
    // loses a record of what a campaign said.
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    [, $harborSegment] = stockBlastSegments($harbor, 'operator@harbor-cleanup.test', 'M15', ['M15 6BH', 'M15 9AA', 'EH8 9YL']);
    [, $ridgeSegment] = stockBlastSegments($ridge, 'operator@ridge-restoration.test', 'EH8', ['M15 6BH', 'EH8 9YL', 'EH8 1AB', 'EH8 2CD']);

    expect($harborSegment->getKey())->toBe($ridgeSegment->getKey());

    tenancy()->initialize($harbor);
    Blast::factory()->aimedAtSegment($harborSegment)->create();
    tenancy()->end();

    signInAt('ridge-restoration.test', 'operator@ridge-restoration.test');

    // Ridge removes its own segment, which nothing in Ridge points at.
    $this->delete('http://ridge-restoration.test/segments/'.$ridgeSegment->getKey())
        ->assertRedirect();

    tenancy()->initialize($ridge);
    expect(Segment::query()->count())->toBe(0);
    tenancy()->end();

    signInAt('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    // Harbor cannot remove its own, because Harbor's blast is aimed at it --
    // and the fact that the identically-numbered segment next door has just
    // been deleted makes no difference to that.
    $this->delete('http://harbor-cleanup.test/segments/'.$harborSegment->getKey())
        ->assertRedirect();

    tenancy()->initialize($harbor);
    expect(Segment::query()->count())->toBe(1)
        ->and(Blast::query()->sole()->segment_id)->toBe($harborSegment->getKey());
    tenancy()->end();
});
