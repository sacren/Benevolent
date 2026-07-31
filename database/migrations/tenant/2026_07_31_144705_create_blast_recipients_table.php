<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One supporter's copy of one blast.
 *
 * Lives in the tenant migration set for the reason `blasts` does (D-1): these
 * rows say who a campaign wrote to, which is the campaign's own business and
 * nobody else's. A central table would pool every campaign's recipients into
 * one place -- the inversion DEC-1 exists to prevent, and the sharpest possible
 * form of it, because this is the record of which people a campaign contacted.
 *
 * **Its reason for existing is resumption, not reporting (D-14).** A blast
 * holds a rule rather than a list, and the rule is evaluated when sending
 * starts. That is what keeps somebody who unsubscribes between composing and
 * sending out of the send -- and it leaves one question unanswerable without a
 * record: *has this person already had it?* A send that dies at 3,000 of 12,000
 * can then only be abandoned or restarted from the top, and restarting
 * double-sends 3,000 people, which is this module's defining failure. So the
 * record is structural. Reporting is what it happens to also make possible.
 *
 * **A row is written before the message is handed to the mailer, never after,
 * and the unique index below is what makes that a guarantee rather than an
 * intention.** The choice is between sending twice and not sending at all, and
 * for bulk mail it is not close: a duplicate is in somebody's inbox and cannot
 * be recalled, while an unsent recipient is a row that says so and can be
 * looked at. The cost is paid honestly -- a process killed between the claim
 * and the send leaves a row with neither `sent_at` nor a reason, which is the
 * true statement that nobody knows.
 *
 * **This is why the guarantee cannot live in a queue lock.** `queue.php` sets
 * `retry_after` to 90 seconds, and a real send runs longer than that, so the
 * database queue hands the same job to a second worker *while the first is
 * still running* -- by default, with no failure and no configuration mistake.
 * That is one campaign, one blast, one dispatch and two senders, which no
 * dispatch-time lock addresses. A lock keyed by campaign is still carried by
 * the job, to turn the second worker away rather than merely make it harmless;
 * the index here is what holds if it does not.
 *
 * **What this table deliberately does not hold: anybody's address (D-10).** It
 * carries a foreign key and nothing else about the person. D-10 resolved that
 * an erasure reaches `supporters` and nothing else, and that resolution is only
 * honest if no other table quietly becomes a second copy of the same people --
 * which is what a denormalized `email` here would be, on every recipient of
 * every blast, forever. Measured by running a deletion and counting rows rather
 * than by reading this file (Blueprint §5), and the columns are the whole of
 * the answer: there is nowhere here for a name, an address or a postcode to be.
 *
 * Note the deliberate difference from the audit trail, which faces the opposite
 * way. An audit entry captures its subject's label at write time *because* it
 * must stay readable after the subject is gone. A recipient row must not,
 * because the subject going is precisely an erasure and the label is the thing
 * being erased. Both rules come from asking what the record is for: one is
 * evidence about an operator's authority, the other is a bookmark in a send.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blast_recipients', function (Blueprint $table) {
            $table->id();

            // Cascading, unlike `blasts.operator_id`, and the difference is
            // what the row is *about*. A blast outlives its author, so losing
            // the author must not lose the blast. These rows are part of one
            // blast's own record and mean nothing without it -- an orphaned
            // recipient row names a message that no longer exists in a database
            // where nothing else refers to it.
            //
            // Note that nothing in the application deletes a blast. This says
            // what should happen if anything ever does, rather than assuming
            // nothing will.
            $table->foreignId('blast_id')->constrained()->cascadeOnDelete();

            // Nulled on delete, never cascaded, and this is D-10's answer in
            // the schema. Cascading would delete the recipient rows of anybody
            // erased, so a blast would afterwards claim it reached 5,000 people
            // while holding 4,999 rows -- the count quietly wrong, in the
            // direction that understates what a campaign did. Nulling keeps the
            // count honest and forgets who: the row still says a message went
            // out, and no longer says to whom, which is exactly what an erasure
            // should leave behind.
            $table->foreignId('supporter_id')->nullable()->constrained()->nullOnDelete();

            // When the message was handed to the mailer, and null until it was.
            //
            // Distinct from `created_at`, which is when the recipient was
            // claimed. The gap between them is the whole of the at-most-once
            // guarantee: within it, a crash leaves a claim that no retry will
            // send, and that is the outcome this module chooses over the
            // alternative.
            $table->timestamp('sent_at')->nullable();

            // Why this one message did not go, where the campaign can read it.
            //
            // Per recipient rather than per blast, because one refused address
            // is not a reason to stop writing to the other eleven thousand
            // people -- and because "the send failed" tells an operator nothing
            // they can act on, while "this address was rejected" does.
            //
            // Whatever wrote this is a mail transport's own complaint, so it is
            // treated as text of unknown length rather than given a limit that
            // would truncate the useful half.
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // **One supporter gets one copy of one blast, said by the database.**
            //
            // This is the module's defining safety property, and it is written
            // here for the same reason `blasts`' check constraint is written in
            // its migration: a controller, a queued job, a retried job, a second
            // worker that took the job after `retry_after` elapsed, a console
            // command and a hand-written query all reach this table, and only
            // one of them would be reviewed for it.
            //
            // A claim is an insert that either succeeds or violates this index,
            // so "has this person already had it?" is answered by the write
            // itself rather than by a read that another worker can race.
            //
            // Note what PostgreSQL does with the null half: nulls do not
            // conflict, so several erased supporters leave several rows with a
            // null `supporter_id` and no collision. That is correct -- they are
            // different people, and the index exists to stop one person being
            // written to twice, not to count nulls.
            $table->unique(['blast_id', 'supporter_id']);
        });

        // A message cannot both have gone and have failed to go.
        //
        // The same shape as `blasts_draft_has_not_been_queued` and for the same
        // reason: a row claiming both is a row nobody can read, and the state it
        // describes is one no correct path produces. Written as raw DDL because
        // the schema builder cannot express a check constraint at all, and
        // through the builder's own connection rather than DB::statement(), so
        // it cannot land centrally while the table it constrains went to a
        // campaign.
        //
        // Stated at its true strength: this forbids the contradiction, not the
        // third state. A row with neither is legal and meaningful -- it is a
        // recipient claimed by a send that stopped before reaching them.
        Schema::getConnection()->statement(
            'alter table "blast_recipients" add constraint "blast_recipients_sent_or_failed" '
            .'check (not ("sent_at" is not null and "failure_reason" is not null))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The constraint belongs to the table and goes with it.
        Schema::dropIfExists('blast_recipients');
    }
};
