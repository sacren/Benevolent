<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message a campaign has written to the people on its list.
 *
 * Lives in the tenant migration set for the reason `supporters` and
 * `supporter_imports` do (D-1): a blast is one campaign's own message, sent in
 * that campaign's name, to that campaign's own people. A central blasts table
 * would pool every campaign's outbound mail into one place -- the inversion
 * DEC-1 exists to prevent, and here it would be a reader of one campaign able
 * to see what another campaign said to its supporters.
 *
 * **What a blast is addressed to, and when that audience is fixed (D-14).** A
 * blast holds a *rule*, not a list of people, and the rule is evaluated when
 * sending starts rather than when the message is written. Three shapes were on
 * the table and they differ in what they promise:
 *
 *   (a) stored criteria evaluated at send;
 *   (b) a recipient list materialized when the blast is composed;
 *   (c) (a), plus a per-recipient record written as each message goes out.
 *
 * (b) is ruled out on correctness rather than on cost. Somebody who
 * unsubscribes between composing and sending is mailed anyway, which is the one
 * outcome bulk email must never produce -- and its cost is perfectly
 * affordable, so economy is not what decides it (materializing 225,000
 * recipients measured at 908 ms and 23 MB).
 *
 * (c) is what this table serves, and the deciding argument is resumption rather
 * than reporting. A send that dies part-way must not restart from the top, so
 * something has to record who was already reached; without it the only safe
 * options are abandon or double-send, and double-sending is this module's
 * defining failure. **That per-recipient record is not in this migration.** It
 * arrives with the sending path that writes it, on this application's own
 * precedent -- `supporter_imports` was not created alongside `supporters`
 * either; it shipped with the job that fills it, because a table nobody writes
 * is a guess at the shape of a writer that does not exist yet. What its columns
 * hold is a data-lifecycle question too (an address stored per recipient would
 * be a fifth place a campaign's people live), and that belongs with the step
 * that decides it.
 *
 * The cost of holding a rule rather than a list is that the count an operator
 * sees before sending is a prediction rather than a promise, and any surface
 * showing it has to say so rather than present one as the other.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blasts', function (Blueprint $table) {
            $table->id();

            // Who wrote it. Nullable and nulled on delete rather than
            // cascading, for the reason supporter_imports.operator_id is: the
            // record of what left this campaign must outlive whoever sent it,
            // and far more so here, because the message is already in other
            // people's inboxes and deleting the row would not recall it.
            //
            // This is not the audit trail and does not reopen D-7 or pre-empt
            // D-17. It is one column on the blast's own record answering "who
            // wrote this", which is the first question anyone asks about a
            // message that went out wrong. Whether a *send* is an event the
            // trail records is a separate question, owned by the step that
            // builds sending.
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            // What the message says. Deliberately two plain columns and no
            // template language: nothing here substitutes a supporter's name
            // into anything, and whether it ever should is a later question
            // asked with a real message in hand. How the body is rendered --
            // as text, as HTML, or both -- belongs to whatever composes the
            // mail, not to the column that stores it.
            $table->string('subject');
            $table->text('body');

            // The stored half of the audience rule, and the *only* half.
            //
            // Null means every supporter this campaign may contact. A list of
            // prefixes narrows that to the postcodes the campaign is aiming at,
            // matched against a column stored exactly as the source gave it and
            // therefore of inconsistent case and spacing -- so how the match is
            // written is a real question for the step that writes it, not a
            // `where like` anybody should assume.
            //
            // Subscribed-only is deliberately absent, and its absence is the
            // guarantee. It is not a criterion an operator chooses and so it is
            // not a criterion a blast can store: a row that could record "send
            // to unsubscribed people too" is a row that makes that sendable.
            // The condition belongs in the query, where nothing can turn it off.
            $table->json('postcode_prefixes')->nullable();

            // Deliberately the literal rather than BlastStatus::default(). A
            // migration must produce the same schema whenever it runs, and
            // under database-per-tenant it runs again for every campaign at
            // whatever date that campaign is provisioned -- so a default read
            // out of application code would give campaigns created after an
            // edit a different column default from the ones already
            // provisioned. A test pins the literal and the enum to each other
            // instead, exactly as `supporters` and `supporter_imports` do.
            $table->string('status')->default('draft');

            // When the campaign committed this blast to sending.
            //
            // **This is the irreversibility, and it is set earlier than the
            // word "sent" suggests.** A blast that has merely been queued is
            // already unrecallable: a worker may claim it at any instant, and
            // there is no safe window in which to edit or re-send it. So the
            // boundary that matters is not sent-versus-sending, it is draft
            // versus everything else, and this column is the fact that says
            // which side a row is on. The check constraint below is what stops
            // the two from ever disagreeing.
            $table->timestamp('queued_at')->nullable();

            // When the send reached a state it will not leave. Its absence is
            // what makes a blast still in flight, which is the question any
            // surface reporting progress has to ask -- the same job
            // supporter_imports.finished_at does, and named the same way.
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });

        // The one property of this module that no later fix restores, written
        // where no code path can get around it.
        //
        // You cannot unsend. So "this blast has been committed to sending" must
        // be a fact about the row rather than a convention the application
        // remembers -- a controller, a queued job, a seeder, a console command
        // and a hand-written query all reach this table, and only one of them
        // would be reviewed for it.
        //
        // Both directions in one statement, because each is a real mistake. A
        // row that is a draft *and* carries a queued_at claims to be editable
        // after the campaign let it go; a row that has left draft *without* one
        // has been committed with no record of when, which is the same as not
        // knowing whether it was.
        //
        // Raw DDL because the schema builder cannot express a check constraint
        // at all -- Blueprint has no check() method -- so this is forced in the
        // way `supporters`' lower(email) index was forced, rather than chosen
        // for symmetry with it.
        //
        // Written through the schema builder's own connection rather than
        // DB::statement(), so the constraint cannot land anywhere other than
        // where the table just went: under tenancy the default connection is a
        // moving target, and one that landed centrally would leave every
        // campaign's blasts quietly unconstrained.
        //
        // Stated at its true strength, which is less than immutability: this
        // makes the two states mutually exclusive, not the row frozen. A
        // deliberate update can still walk a blast back to draft by clearing
        // both columns together. What it removes is every way of getting there
        // by accident.
        Schema::getConnection()->statement(
            'alter table "blasts" add constraint "blasts_draft_has_not_been_queued" '
            .'check ((status = \'draft\') = (queued_at is null))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The constraint belongs to the table and goes with it.
        Schema::dropIfExists('blasts');
    }
};
