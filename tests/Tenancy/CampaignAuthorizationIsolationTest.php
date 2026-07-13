<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Authorization\Permission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * The DEC-1 isolation guarantee, extended from data to authority.
 *
 * CampaignIsolationTest proves a campaign cannot read another campaign's rows.
 * This proves the consequence for the authorization spine: authority is a
 * property of an operator *inside one campaign*, so being an Owner of one
 * campaign confers nothing anywhere else. Nothing enforces that separately —
 * it falls out of the role living in a column, in a table, in a database only
 * that campaign is connected to — and a test is what keeps it falling out.
 *
 * These tests provision their own campaigns rather than using the campaign
 * harness, which keeps one campaign per file inside a transaction that a switch
 * to a second campaign would purge (L-10).
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('each campaign grants ownership to its own first operator', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The registration rule asks whether the campaign has any operator yet. That
    // question is answered by whichever database is connected, so each campaign
    // gets its own first owner -- Ridge Restoration's founder is not demoted to
    // Staff because Harbor Cleanup already has one. Were the check ever to
    // consult the central database, every campaign after the first would be
    // founded by someone who could not govern it.
    $register = fn (string $email) => app(CreatesNewUsers::class)->create([
        'name' => 'Founder',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    tenancy()->initialize($harbor);
    expect(DB::connection('tenant')->getDatabaseName())->toBe($harbor->database()->getName());
    $harborFounder = $register('founder@harbor-cleanup.test');

    tenancy()->end();

    tenancy()->initialize($ridge);
    expect(DB::connection('tenant')->getDatabaseName())->toBe($ridge->database()->getName());
    $ridgeFounder = $register('founder@ridge-restoration.test');

    expect($harborFounder->role)->toBe(OperatorRole::Owner)
        ->and($ridgeFounder->role)->toBe(OperatorRole::Owner);

    // The control. Both campaigns holding exactly one operator is what proves
    // the two registrations landed in different databases; had they pooled, the
    // second would have been the campaign's second operator and joined as Staff,
    // and the assertion above would already have failed for that reason rather
    // than the one it claims.
    expect($ridge->run(fn () => User::query()->count()))->toBe(1)
        ->and($harbor->run(fn () => User::query()->count()))->toBe(1)
        ->and($harbor->database()->getName())->not->toBe($ridge->database()->getName());
});

test('the same email is a separate operator with a separate role in each campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // One human, working on two campaigns: governing the first and helping with
    // the second. There is no shared identity here -- these are two unrelated
    // rows that happen to carry the same address, which is possible at all only
    // because the unique index on `email` is per campaign database.
    tenancy()->initialize($harbor);
    User::factory()->owner()->create(['email' => 'consultant@example.test']);

    tenancy()->end();

    tenancy()->initialize($ridge);
    User::factory()->create(['email' => 'consultant@example.test']);

    $inRidge = User::query()->where('email', 'consultant@example.test')->sole();

    expect($inRidge->role)->toBe(OperatorRole::Staff)
        ->and(Gate::forUser($inRidge)->allows(Permission::ManageOperators->value))->toBeFalse();

    tenancy()->end();
    tenancy()->initialize($harbor);

    $inHarbor = User::query()->where('email', 'consultant@example.test')->sole();

    // The paired half, and what makes the denial above evidence rather than a
    // gate that says no to everyone: the same address, the same permission, the
    // same call -- answered differently because the campaign asking is
    // different.
    expect($inHarbor->role)->toBe(OperatorRole::Owner)
        ->and(Gate::forUser($inHarbor)->allows(Permission::ManageOperators->value))->toBeTrue();
});

test('an owner carries no authority into another campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // The sharpest statement of the guarantee: an operator who exists in one
    // campaign and has never been enrolled in the other. Not a lesser role
    // elsewhere -- no presence at all.
    tenancy()->initialize($harbor);
    $owner = User::factory()->owner()->create(['email' => 'owner@harbor-cleanup.test']);

    expect(Gate::forUser($owner)->allows(Permission::ManageOperators->value))->toBeTrue();

    tenancy()->end();
    tenancy()->initialize($ridge);

    // Ridge Restoration has never heard of them. The operator table it is
    // connected to holds nobody, so there is no row to carry a role.
    expect(User::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'owner@harbor-cleanup.test')->exists())->toBeFalse();
});
