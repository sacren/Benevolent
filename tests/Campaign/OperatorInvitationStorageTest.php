<?php

declare(strict_types=1);

use App\Authorization\OperatorRole;
use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The record of a campaign admitting somebody (D-54), and what an erasure
 * reaches in it (D-55's first half).
 *
 * These assert properties of the *schema*, which is the whole of what Step 2
 * built, and every row below is written straight to the table rather than
 * through any writer. That is deliberate rather than a limitation --
 * these claims are about what the database will hold whoever eventually writes
 * it, and a test driven through a writer would be satisfied by a writer that
 * happens to behave while the schema permits otherwise.
 *
 * §3's convention binds every fixture here: two invitations, two operators,
 * two roles, and the assertion names the row the database must *not* have
 * taken.
 */

test('the campaign database carries the record of who it admitted', function (): void {
    expect(Schema::hasTable('operator_invitations'))->toBeTrue()
        ->and(Schema::hasColumns('operator_invitations', [
            'email',
            'role',
            'token',
            'invited_by_id',
            'invited_by_label',
            'accepted_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    // **And not the columns this table deliberately does not have**, because
    // "carries these" is satisfied by a table carrying them and more, and each
    // of these absences is an argument the migration makes. There is no
    // `expires_at`, because a lifetime is a comparison against `created_at` at
    // read and storing one would buy a column and a second thing to keep
    // current. There is no `user_id`, because the row records an act aimed at
    // an address rather than pointing at the operator it produced -- a key
    // would take the record away with the operator or leave a dangling null.
    // And there is no `status`, for the reason `unsubscribes` has none: the
    // credential's presence is the state, so nothing here can drift out of
    // agreement with a column somebody forgot to update.
    expect(Schema::hasColumn('operator_invitations', 'expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('operator_invitations', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('operator_invitations', 'status'))->toBeFalse();
});

test('the column mints the credential, so no writer can produce an invitation without one', function (): void {
    // D-16(a)'s measured finding applied to a third credential: a model
    // `creating` hook is bypassed by any writer that does not go through
    // Eloquent. This table had no model at all until Step 3, so a generator in
    // application code would have had nothing to live in -- and a model that
    // exists now is still bypassed by every row this file writes directly.
    DB::connection('tenant')->table('operator_invitations')->insert([
        ['email' => 'first@example.test', 'created_at' => now(), 'updated_at' => now()],
        ['email' => 'second@example.test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $tokens = DB::connection('tenant')->table('operator_invitations')->pluck('token');

    expect($tokens)->toHaveCount(2)
        ->and($tokens->filter())->toHaveCount(2)
        // Two, and distinct: a generator handing one value to every row would
        // satisfy both assertions above.
        ->and($tokens->unique())->toHaveCount(2);
});

test('the credential is the database\'s to mint and the writer\'s to remove', function (): void {
    // The configuration invariant behind the behaviour (L-14's pairing), and
    // the place the contrast with the other two credentials is pinned rather
    // than described. `supporters.unsubscribe_token` is NOT NULL because every
    // supporter must have one; this column is nullable because its *absence*
    // is what "already used" means, and a NOT NULL column could not express a
    // spent invitation without a second column to say so.
    $column = DB::connection('tenant')->selectOne(
        'select column_default, is_nullable from information_schema.columns '
        .'where table_name = ? and column_name = ?',
        ['operator_invitations', 'token'],
    );

    expect($column?->column_default)->toContain('gen_random_uuid()')
        ->and($column?->is_nullable)->toBe('YES');

    // Paired with the column it is deliberately unlike, in the same run, so
    // that this cannot read as a description of a difference that is not there.
    $supporters = DB::connection('tenant')->selectOne(
        'select is_nullable from information_schema.columns '
        .'where table_name = ? and column_name = ?',
        ['supporters', 'unsubscribe_token'],
    );

    expect($supporters?->is_nullable)->toBe('NO');
});

test('the database refuses two invitations holding one credential', function (): void {
    DB::connection('tenant')->table('operator_invitations')->insert([
        ['email' => 'holder@example.test', 'created_at' => now(), 'updated_at' => now()],
        ['email' => 'other@example.test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $held = DB::connection('tenant')->table('operator_invitations')->where('email', 'holder@example.test')->value('token');

    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'other@example.test')
        ->update(['token' => $held]));

    // SQLSTATE 23505 -- unique violation. By code rather than by message, so a
    // reworded error cannot weaken it.
    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23505');

    // The claim in full: a shared credential would admit an arbitrary one of
    // two people and no reader of either row could tell which was meant.
    expect(DB::connection('tenant')->table('operator_invitations')->distinct()->count('token'))->toBe(2);
});

test('a campaign may hold only one live invitation to a person, whatever its casing', function (): void {
    DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'Jean.Sacren@Example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'jean.sacren@example.test', 'created_at' => now(), 'updated_at' => now(),
    ]));

    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23505');

    // **The positive half, in the same run**, without which this passes just as
    // happily against a table that refuses every insert. A different person is
    // invitable while the first invitation is outstanding.
    DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'somebody.else@example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::connection('tenant')->table('operator_invitations')->count())->toBe(2);

    // And the address is stored exactly as it arrived -- only the *match* is
    // folded, which is the asymmetry `supporters` already follows, since a
    // folded value is recoverable from the raw one and never the reverse.
    expect(DB::connection('tenant')->table('operator_invitations')
        ->whereRaw('lower(email) = ?', ['jean.sacren@example.test'])->value('email'))
        ->toBe('Jean.Sacren@Example.test');
});

test('a campaign may invite somebody again once their first invitation is spent', function (): void {
    // The half a plain unique index would have got wrong, and the reason the
    // index is partial. Somebody who joined and later left is an ordinary
    // person for a campaign to invite a second time; an index over every row
    // would make them permanently un-invitable, and the campaign would have no
    // way to see why.
    DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'returning@example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'returning@example.test')
        ->update(['token' => null, 'accepted_at' => now()]);

    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'returning@example.test', 'created_at' => now(), 'updated_at' => now(),
    ]));

    expect($refusal)->toBeNull();

    // Two rows for one person: the spent record of the first admission, and a
    // live invitation. The assertion names which is which, because "there are
    // two rows" is satisfied by two live ones -- the state the index above
    // exists to forbid.
    $rows = DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'returning@example.test')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->token)->toBeNull()
        ->and($rows[0]->accepted_at)->not->toBeNull()
        ->and($rows[1]->token)->not->toBeNull()
        ->and($rows[1]->accepted_at)->toBeNull();
});

test('an invitation that has been accepted may not still hold its credential', function (): void {
    DB::connection('tenant')->table('operator_invitations')->insert([
        ['email' => 'joining@example.test', 'created_at' => now(), 'updated_at' => now()],
        ['email' => 'waiting@example.test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // The contradiction: a spent invitation whose link still resolves. It is
    // not hypothetical -- `.env.example` ships MAIL_MAILER=log, which writes
    // the whole message including the link into the application log, so a
    // credential that outlives its use is a live key sitting in a log file.
    $refusal = refusalFrom(fn () => DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'joining@example.test')
        ->update(['accepted_at' => now()]));

    // SQLSTATE 23514 -- check violation.
    expect($refusal)->not->toBeNull()
        ->and((string) $refusal->getCode())->toBe('23514');

    // **The positive half**: the same acceptance, done the way a writer must
    // do it, is permitted. Without this the assertion above is satisfied by a
    // constraint that refuses every update to this table.
    DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'joining@example.test')
        ->update(['token' => null, 'accepted_at' => now()]);

    expect(DB::connection('tenant')->table('operator_invitations')->where('email', 'joining@example.test')->value('token'))
        ->toBeNull();

    // **And the third state is legal, deliberately.** The constraint forbids
    // the contradiction, not a row holding neither a credential nor an
    // acceptance -- which is exactly what a withdrawn invitation is, since
    // Phase 6 Step 4 chose to keep the row (WithdrawOperatorInvitation). A
    // biconditional here would have decided that question by accident, in the
    // step that did not hold it, and would now refuse every withdrawal.
    $neither = refusalFrom(fn () => DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'waiting@example.test')
        ->update(['token' => null]));

    expect($neither)->toBeNull();
});

test('the authority an invitation grants defaults to the least there is', function (): void {
    // Written straight to the table, naming no role, so the value under test
    // can only have come from the database.
    DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'unnamed@example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $stored = DB::connection('tenant')->table('operator_invitations')
        ->where('email', 'unnamed@example.test')->value('role');

    // Pinned to each other, so the migration's hardcoded literal cannot drift
    // from the enum in either direction...
    expect($stored)->toBe(OperatorRole::default()->value);

    // ...and pinned to the intended choice, so the pair cannot move together
    // and stay green. An invitation whose author said nothing must not be able
    // to hand somebody authority over the campaign.
    expect(OperatorRole::default())->toBe(OperatorRole::Staff)
        ->and(OperatorRole::Staff->allows(Permission::ManageOperators))->toBeFalse();

    // And the column takes the other role when it is named, so the default
    // above is a default rather than the only value the column can hold --
    // which is how this would read as green against a column nobody can write.
    DB::connection('tenant')->table('operator_invitations')->insert([
        'email' => 'named@example.test', 'role' => OperatorRole::Owner->value, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::connection('tenant')->table('operator_invitations')->where('email', 'named@example.test')->value('role'))
        ->toBe('owner');
});

test('erasing somebody who was invited and never joined leaves them nowhere in the campaign', function (): void {
    // **D-55's sharpest question, asked by running a deletion rather than by
    // analogy.** Blueprint §5 records that a module ingesting a file holds
    // personal data about people who never became records, and that for them
    // retention is not the tidier alternative to erasure but the whole of it,
    // because there is no row to select. An invitation is that shape arriving
    // from the opposite direction: the person is not a supporter, not yet an
    // operator, and may never be either -- but they *do* have a row, and it is
    // the only thing in the campaign that names them. So the conclusion does
    // not transfer. A record-shaped erasure reaches them completely, and this
    // is the measurement that says so.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);

    DB::connection('tenant')->table('operator_invitations')->insert([
        ['email' => 'newcomer@example.test', 'invited_by_id' => $owner->getKey(), 'invited_by_label' => $owner->email, 'created_at' => now(), 'updated_at' => now()],
        // **The row the deletion must not take.** Without a second invitation
        // the assertion below is satisfied by anything that empties the table,
        // which is the fixture blind spot Blueprint v0.32 records.
        ['email' => 'bystander@example.test', 'invited_by_id' => $owner->getKey(), 'invited_by_label' => $owner->email, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Present before the deletion, so an empty result afterwards is the
    // deletion rather than a search that could find nothing. One home, and it
    // is this table -- which is the finding: an invited person is held in
    // exactly one place, unlike a supporter and unlike an operator.
    expect(campaignColumnsHolding('newcomer@example.test'))->toBe(['operator_invitations.email'])
        ->and(campaignColumnsHolding('bystander@example.test'))->toBe(['operator_invitations.email']);

    DB::connection('tenant')->table('operator_invitations')->where('email', 'newcomer@example.test')->delete();

    expect(campaignColumnsHolding('newcomer@example.test'))->toBe([])
        ->and(campaignColumnsHolding('bystander@example.test'))->toBe(['operator_invitations.email']);
});

test('removing an operator keeps the campaign\'s record of how they were admitted', function (): void {
    // **The same instrument pointed at a subject it has never been pointed
    // at.** Every previous use of this scan deletes a supporter. Phase 0 Step
    // 11 measured an operator's deletion by hand and found two rows carrying
    // their address afterwards -- the trail's `subject_label`, deliberately,
    // and an abandoned reset row. This module adds two more, and both are the
    // same decision rather than new defects: removal is an access change, not
    // an erasure request, and a record of how authority was granted that the
    // grantee's departure deletes is not a record anybody can rely on.
    $owner = User::factory()->owner()->create(['email' => 'governor@example.test']);
    $leaving = User::factory()->owner()->create(['email' => 'leaving@example.test']);

    DB::connection('tenant')->table('operator_invitations')->insert([
        // The invitation that admitted the operator who is about to go.
        ['email' => 'leaving@example.test', 'role' => OperatorRole::Owner->value, 'token' => null, 'accepted_at' => now(), 'invited_by_id' => $owner->getKey(), 'invited_by_label' => $owner->email, 'created_at' => now(), 'updated_at' => now()],
        // One they sent themselves, still outstanding. The person it names is
        // somebody else, so the departure must not reach it.
        ['email' => 'newcomer@example.test', 'role' => OperatorRole::Staff->value, 'token' => (string) Str::uuid(), 'accepted_at' => null, 'invited_by_id' => $leaving->getKey(), 'invited_by_label' => $leaving->email, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // The abandoned reset row Phase 0 Step 11 found, written the way the broker
    // writes it: keyed by address, with no user_id to cascade.
    DB::connection('tenant')->table('password_reset_tokens')->insert([
        'email' => 'leaving@example.test', 'token' => 'irrelevant', 'created_at' => now(),
    ]);

    expect(campaignColumnsHolding('leaving@example.test'))->toBe([
        'audit_entries.subject_label',
        'operator_invitations.email',
        'operator_invitations.invited_by_label',
        'password_reset_tokens.email',
        'users.email',
    ]);

    $leaving->delete();

    // Four homes, measured. `users.email` is the only one the deletion empties
    // -- which is the whole statement Phase 0 recorded as a decision, now with
    // two more rows standing behind it.
    expect(campaignColumnsHolding('leaving@example.test'))->toBe([
        'audit_entries.subject_label',
        'operator_invitations.email',
        'operator_invitations.invited_by_label',
        'password_reset_tokens.email',
    ]);

    // **And the operator who stays is untouched**, so the list above is what a
    // deletion leaves rather than what the campaign happens to hold.
    expect(campaignColumnsHolding('governor@example.test'))->toBe([
        'audit_entries.subject_label',
        'operator_invitations.invited_by_label',
        'users.email',
    ]);

    // **The credential half, which is the one that could have been a defect
    // rather than a decision.** The invitation that admitted them is spent, so
    // their departure leaves behind no link that would let them back in; the
    // one still holding a credential belongs to somebody else and is correctly
    // untouched.
    expect(DB::connection('tenant')->table('operator_invitations')->whereNotNull('token')->pluck('email')->all())
        ->toBe(['newcomer@example.test']);
});
