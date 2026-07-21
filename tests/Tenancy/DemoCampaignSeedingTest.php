<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

/**
 * The demo campaign seeder, and specifically its claim to be safe to re-run.
 *
 * That claim is in the seeder's own docblock and it is the kind this project
 * has learned to distrust: it is believed without being checked, and the cost
 * of it being wrong lands on whoever runs the command a second time.
 *
 * **What this file does not guard, measured rather than assumed.** The seeder
 * matches on `lower(email)` because the address is the identity (D-8), and it
 * would be natural to read these tests as covering that. They do not: the
 * seeder looks a supporter up with the same literal it inserted, so an exact
 * comparison finds it every time and rewriting the scope to compare the column
 * directly leaves both tests below green. The case fold is guarded in
 * tests/Campaign/SupporterStorageTest.php, where the lookup and the stored
 * value deliberately differ. The hazard here is a genuinely different one --
 * a second run duplicating what the first wrote.
 *
 * L-17's second half is what makes the re-run real rather than hypothetical:
 * seeded fixtures lag independently of schema, so catching an existing campaign
 * up on new fixtures means running this seeder again over one that is already
 * populated. That is the ordinary case, not the exception.
 *
 * Provisioning happens against the testing central database and every campaign
 * created here is deleted afterwards, so nothing touches a development one.
 */
afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('seeding the demo campaign twice leaves it exactly as once did', function (): void {
    Artisan::call('migrate:fresh');

    expect(Artisan::call('db:seed', ['--class' => 'TenantSeeder']))->toBe(Command::SUCCESS);

    $campaign = Tenant::query()->where('slug', 'demo-campaign')->sole();

    $afterFirstRun = $campaign->run(fn (): array => [
        'operators' => User::query()->count(),
        'supporters' => Supporter::query()->orderBy('email')->pluck('email')->all(),
    ]);

    // The positive half: the first run actually produced a campaign worth
    // looking at. Without it, "twice equals once" is satisfied perfectly by a
    // seeder that does nothing at all, both times.
    expect($afterFirstRun['operators'])->toBe(1)
        ->and($afterFirstRun['supporters'])->toHaveCount(4);

    expect(Artisan::call('db:seed', ['--class' => 'TenantSeeder']))->toBe(Command::SUCCESS);

    $afterSecondRun = $campaign->run(fn (): array => [
        'operators' => User::query()->count(),
        'supporters' => Supporter::query()->orderBy('email')->pluck('email')->all(),
    ]);

    expect($afterSecondRun)->toBe($afterFirstRun);
});

test('the seeded list carries the shapes a real list actually contains', function (): void {
    // The fixtures exist to exercise the page against the data the schema was
    // designed for, so this asserts they still do. A seeder quietly narrowed to
    // four tidy split-name rows would leave the null-name and unsubscribed
    // paths unrendered by anything anyone looks at, and no test would notice.
    Artisan::call('migrate:fresh');
    Artisan::call('db:seed', ['--class' => 'TenantSeeder']);

    $shapes = Tenant::query()->where('slug', 'demo-campaign')->sole()->run(fn (): array => [
        'nameless' => Supporter::query()->whereNull('name')->count(),
        'singleString' => Supporter::query()->whereNotNull('name')->whereNull('given_name')->count(),
        'split' => Supporter::query()->whereNotNull('given_name')->whereNotNull('family_name')->count(),
        'unsubscribed' => Supporter::query()->where('subscription_status', 'unsubscribed')->count(),
    ]);

    expect($shapes['nameless'])->toBe(1)
        ->and($shapes['singleString'])->toBe(1)
        ->and($shapes['split'])->toBe(2)
        ->and($shapes['unsubscribed'])->toBe(1);
});
