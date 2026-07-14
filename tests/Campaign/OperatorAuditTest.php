<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Audit\OperatorAuditObserver;
use App\Authorization\OperatorRole;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('registering an operator records that they joined, and the authority they claimed', function (): void {
    $this->post(route('register.store'), [
        'name' => 'First Arrival',
        'email' => 'first@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    // sole() is doing work here: it fails if the registration wrote more than
    // one entry, which is the "records everything" failure this trail is scoped
    // to avoid.
    $entry = AuditEntry::query()->sole();
    $operator = User::query()->where('email', 'first@example.test')->sole();

    expect($entry->event)->toBe(AuditEvent::OperatorRegistered)
        ->and($entry->subject_type)->toBe(User::class)
        ->and($entry->subject_id)->toBe($operator->getKey())
        ->and($entry->subject_label)->toBe('first@example.test')
        ->and($entry->changes)->toBe(['role' => ['from' => null, 'to' => 'owner']])
        ->and($entry->created_at)->not->toBeNull();

    // And no actor, which is the honest answer rather than a gap. Registration
    // is open on every campaign, so the first operator to reach a fresh one
    // claims it as Owner on nobody's authority -- including someone who simply
    // guessed the hostname. An entry naming a granter here would be a fiction.
    expect($entry->actor_id)->toBeNull()
        ->and($entry->actor_label)->toBeNull();
});

test('the trail reports the authority actually granted rather than a constant', function (): void {
    // Guards the shape of the assertion above: an entry hardcoding "owner"
    // would satisfy that test forever. Here the same code path must produce a
    // different answer, because the campaign already has an owner.
    User::factory()->owner()->create(['email' => 'incumbent@example.test']);

    $this->post(route('register.store'), [
        'name' => 'Second Arrival',
        'email' => 'second@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $entries = AuditEntry::query()->orderBy('id')->get();

    // Two entries, not one: the factory-created incumbent is recorded too. That
    // is deliberate -- an operator arriving by an unusual route is exactly the
    // one worth being able to see.
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_label)->toBe('incumbent@example.test')
        ->and($entries[0]->changes)->toBe(['role' => ['from' => null, 'to' => 'owner']])
        ->and($entries[1]->subject_label)->toBe('second@example.test')
        ->and($entries[1]->changes)->toBe(['role' => ['from' => null, 'to' => 'staff']]);
});

test('an operator created without a role named is recorded with the role the database gave them', function (): void {
    // User::create strips `role`, which is not fillable, so the model carries no
    // role attribute while the row it just wrote carries the column's default.
    // Reading the attribute alone would record null and understate the trail.
    $operator = User::create([
        'name' => 'Unnamed Role',
        'email' => 'defaulted@example.test',
        'password' => 'password',
    ]);

    expect(AuditEntry::query()->sole()->changes)
        ->toBe(['role' => ['from' => null, 'to' => 'staff']])
        ->and($operator->refresh()->role)->toBe(OperatorRole::Staff);
});

test('an operator changing their own password is not recorded, while one joining the campaign is', function (): void {
    // The pairing this whole file turns on, and the reason both halves live in
    // one test. A test asserting that something was *not* recorded passes
    // perfectly against an observer that was never attached and records nothing
    // whatsoever -- it is the single easiest guard in this codebase to leave
    // green for the wrong reason. The negative claim is evidence only when a
    // positive one, made through the same recorder in the same run, answers
    // differently.
    $operator = User::factory()->create(['email' => 'settled@example.test']);

    $baseline = AuditEntry::query()->count();

    $this->actingAs($operator)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    // The negative half: an operator's own credentials are their business, not
    // the campaign's roster, so the trail does not move.
    expect(AuditEntry::query()->count())->toBe($baseline);

    // The positive half, through the same recorder, moments later.
    User::factory()->create(['email' => 'newcomer@example.test']);

    expect(AuditEntry::query()->count())->toBe($baseline + 1)
        ->and(AuditEntry::query()->orderByDesc('id')->first()?->subject_label)
        ->toBe('newcomer@example.test');
});

test('removing an operator records that they left, and the entry outlives them', function (): void {
    $operator = User::factory()->create(['email' => 'departing@example.test']);
    $operatorId = $operator->getKey();

    $this->actingAs($operator)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect(User::query()->whereKey($operatorId)->exists())->toBeFalse();

    $entry = AuditEntry::query()->where('event', AuditEvent::OperatorRemoved->value)->sole();

    // The entry still names them, which is the whole point of capturing the
    // label rather than relying on a join: there is nothing left to join to.
    expect($entry->subject_id)->toBe($operatorId)
        ->and($entry->subject_label)->toBe('departing@example.test')
        ->and($entry->changes)->toBeNull();
});

test('a self-removal records no actor, because the operator is signed out before their row is deleted', function (): void {
    // Recorded rather than worked around. ProfileController logs out and *then*
    // deletes, so nobody is authenticated when the model event fires and the
    // actor is genuinely unknowable at that moment.
    //
    // The ordering is load-bearing and must not be reversed to make this
    // prettier: SessionGuard::logout() cycles the remember token through
    // $user->save(), and an Eloquent model that has just been deleted has
    // exists = false, so that save would INSERT -- resurrecting the operator
    // the request set out to remove. The factory sets a remember_token, so this
    // is a live hazard rather than a theoretical one.
    //
    // The gap closes on its own when an Owner can remove someone else: that
    // request stays authenticated throughout, so the actor is populated by the
    // same code with no change. Only self-removal is reachable today.
    $operator = User::factory()->create(['email' => 'departing@example.test']);

    $this->actingAs($operator)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    $entry = AuditEntry::query()->where('event', AuditEvent::OperatorRemoved->value)->sole();

    expect($entry->actor_id)->toBeNull()
        ->and($entry->actor_label)->toBeNull()
        // Paired with the positive claim, so a recorder that wrote nothing at
        // all could not satisfy the assertions above by default.
        ->and($entry->subject_label)->toBe('departing@example.test');
});

test('every model event the audit observer handles is wired to the operator model', function (): void {
    // The configuration invariant, and Step 8's permission sweep in the shape
    // this step needs. Every behavioural test above exercises an event someone
    // remembered to write a test for; this one asks the framework which events
    // the operator model actually dispatches, so an observer method added and
    // left unwired -- or the #[ObservedBy] line deleted -- goes red here even
    // though nothing names it directly.
    //
    // An observer that is written but never attached is not a loud failure. It
    // records nothing, and every "was not recorded" assertion in this file
    // would still pass.
    new User;

    $handled = collect((new ReflectionClass(OperatorAuditObserver::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isConstructor())
        ->map(fn (ReflectionMethod $method): string => $method->getName());

    expect($handled)->not->toBeEmpty();

    foreach ($handled as $modelEvent) {
        expect(Event::hasListeners("eloquent.{$modelEvent}: ".User::class))->toBeTrue(
            "OperatorAuditObserver handles {$modelEvent}, but User dispatches nothing for it."
        );
    }
});
