<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\Supporter;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Illuminate\Support\Facades\Gate;

/*
 * Adding and correcting a supporter, through the routes an operator actually
 * uses. Removal ships with the control that hides it, because it is the one
 * ability the two roles disagree about.
 *
 * SupporterListTest covers the read path; SupporterAuthorizationTest asks who
 * may do what through the gate and the policy directly. This file exercises the
 * write path end to end, and the two things it exists for are the ones that
 * would otherwise reach an operator as a 500 or as a silent refusal: an address
 * that differs only in case, and an edit that must not collide with itself.
 */

test('an operator adds a supporter', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters'), [
            'name' => 'Ama Boateng',
            'given_name' => 'Ama',
            'family_name' => 'Boateng',
            'email' => 'Ama.Boateng@Example.test',
            'postcode' => 'M15 6BH',
        ])
        ->assertRedirect(route('supporters.index'))
        ->assertSessionHasNoErrors();

    $added = Supporter::query()->sole();

    expect($added->name)->toBe('Ama Boateng')
        ->and($added->given_name)->toBe('Ama')
        ->and($added->family_name)->toBe('Boateng')
        ->and($added->postcode)->toBe('M15 6BH')
        // Stored exactly as typed. Only the *match* folds case, which is the
        // same asymmetry the postcode and the name parts follow.
        ->and($added->email)->toBe('Ama.Boateng@Example.test')
        // Not offered on the create form, so the column's default is what
        // decides it -- and the default is that the campaign may write to them.
        ->and($added->subscription_status)->toBe(SubscriptionStatus::Subscribed);
});

test('a supporter may be added with an address and no name at all', function (): void {
    // The row a petition widget produces. Requiring a name would refuse a
    // person the campaign can perfectly well contact, which contradicts the
    // goal's own first clause -- so the form asks for one and does not insist.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters'), ['email' => 'nameless@example.test'])
        ->assertRedirect(route('supporters.index'))
        ->assertSessionHasNoErrors();

    $added = Supporter::query()->sole();

    expect($added->email)->toBe('nameless@example.test')
        ->and($added->name)->toBeNull()
        ->and($added->given_name)->toBeNull()
        ->and($added->family_name)->toBeNull();
});

test('a supporter with no address is refused, and told why', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters'), ['name' => 'Unreachable'])
        ->assertSessionHasErrors('email');

    expect(Supporter::query()->count())->toBe(0);
});

test('an address already on the list is refused as validation, whatever its casing', function (): void {
    // **The trap this whole step inherits, in the shape it actually arrives.**
    // Laravel's own Rule::unique compiles to `where "email" = ?`, so it reports
    // a case variant as available; the insert then reaches the lower(email)
    // index and PostgreSQL refuses it with 23505, which is a 500 rather than an
    // answer. The point of this test is not that the duplicate is rejected --
    // the database would reject it either way -- but that it is rejected *as a
    // validation error on the email field*, which is the difference between
    // telling an operator this person is already on their list and showing them
    // a crash.
    Supporter::factory()->create(['email' => 'Jean.Sacren@Example.test']);

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters'), [
            'name' => 'Jean Sacren',
            'email' => 'jean.sacren@example.test',
        ])
        ->assertSessionHasErrors('email');

    expect(Supporter::query()->count())->toBe(1);

    // The positive half, in the same run: a genuinely new address still gets
    // through. Without it this passes just as happily against a rule that
    // refuses every address, or a route nobody can reach.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters'), ['email' => 'someone.else@example.test'])
        ->assertSessionHasNoErrors();

    expect(Supporter::query()->count())->toBe(2);
});

test('an operator corrects a supporter', function (): void {
    $supporter = Supporter::factory()->create([
        'name' => 'Ines Duarte',
        'email' => 'ines.duarte@example.test',
        'postcode' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
            'name' => 'Inês Duarte',
            'given_name' => 'Inês',
            'family_name' => 'Duarte',
            'email' => 'ines.duarte@example.test',
            'postcode' => '1250-096',
            'subscription_status' => SubscriptionStatus::Subscribed->value,
        ])
        ->assertRedirect(route('supporters.index'))
        ->assertSessionHasNoErrors();

    expect($supporter->fresh()->name)->toBe('Inês Duarte')
        ->and($supporter->fresh()->postcode)->toBe('1250-096');
});

test('editing a supporter without changing their address is not refused as a duplicate', function (): void {
    // Without the rule ignoring the supporter being edited, this is refused on
    // the grounds that the address already belongs to somebody -- namely them.
    // True, and useless: it would make every edit that left the address alone
    // impossible, which is most of them.
    $supporter = Supporter::factory()->create(['email' => 'Jean.Sacren@Example.test']);

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
            'name' => 'Jean Sacren',
            'email' => 'Jean.Sacren@Example.test',
            'subscription_status' => SubscriptionStatus::Subscribed->value,
        ])
        ->assertSessionHasNoErrors();

    // And the same edit with the casing changed, which is the form of it the
    // fold has to survive: still this person, still not a duplicate.
    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
            'name' => 'Jean Sacren',
            'email' => 'JEAN.SACREN@EXAMPLE.TEST',
            'subscription_status' => SubscriptionStatus::Subscribed->value,
        ])
        ->assertSessionHasNoErrors();

    // The negative half, so the ignore cannot have simply switched the rule
    // off: somebody else's address is still refused.
    $other = Supporter::factory()->create(['email' => 'other@example.test']);

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
            'email' => 'OTHER@example.test',
            'subscription_status' => SubscriptionStatus::Subscribed->value,
        ])
        ->assertSessionHasErrors('email');

    expect($other->fresh()->email)->toBe('other@example.test');
});

test('unsubscribing is how a campaign stops contacting someone', function (): void {
    // The status is editable here and deliberately not on the create form. It
    // is also the reason removal is the exceptional act: this keeps the record
    // that stops a later import putting them back on the list.
    $supporter = Supporter::factory()->create(['email' => 'quiet@example.test']);

    expect($supporter->subscription_status)->toBe(SubscriptionStatus::Subscribed);

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
            'email' => 'quiet@example.test',
            'subscription_status' => SubscriptionStatus::Unsubscribed->value,
        ])
        ->assertSessionHasNoErrors();

    expect($supporter->fresh()->subscription_status)->toBe(SubscriptionStatus::Unsubscribed)
        // Still on the list. Unsubscribing is not a deletion, and a page that
        // quietly dropped them would lose exactly the record this exists for.
        ->and(Supporter::query()->whereKey($supporter->getKey())->exists())->toBeTrue();
});

test('the forms are reachable, and the edit form carries the supporter it edits', function (): void {
    $supporter = Supporter::factory()->create(['email' => 'editing@example.test']);
    $operator = User::factory()->create();

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters/create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('supporters/Create'));

    $this->actingAs($operator)
        ->get($this->campaignUrl('/supporters/'.$supporter->getKey().'/edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('supporters/Edit')
            ->where('supporter.email', 'editing@example.test')
        );
});

test('the write pages ask the policy, and refuse an operator the policy refuses', function (): void {
    // Paired with every success above: withdrawing the grant turns the same
    // requests into refusals, which is what shows the successes were the policy
    // answering rather than nobody asking.
    $supporter = Supporter::factory()->create();
    $operator = User::factory()->create();

    Gate::define(Permission::EditSupporters->value, fn (): bool => false);

    $this->actingAs($operator)->get($this->campaignUrl('/supporters/create'))->assertForbidden();
    $this->actingAs($operator)->post($this->campaignUrl('/supporters'), ['email' => 'new@example.test'])->assertForbidden();
    $this->actingAs($operator)->get($this->campaignUrl('/supporters/'.$supporter->getKey().'/edit'))->assertForbidden();
    $this->actingAs($operator)->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
        'email' => 'new@example.test',
        'subscription_status' => SubscriptionStatus::Subscribed->value,
    ])->assertForbidden();

    expect(Supporter::query()->count())->toBe(1);
});
