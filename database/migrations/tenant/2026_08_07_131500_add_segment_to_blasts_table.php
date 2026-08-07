<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a blast points when its aim has a name (D-26).
 *
 * **D-26 resolved: shape (b) — a blast may point at a segment *or* carry its
 * own rule, and `postcode_prefixes` stays exactly where it was.** Three shapes
 * were on the table: (a) no pointer at all, (b) this, and (c) extraction, with
 * `blasts.postcode_prefixes` migrated into `segments` and dropped. What decided
 * it is what an already-committed blast must still be able to say.
 *
 * **(a) is refused because D-23 was resolved on a reader only this migration
 * creates.** `Segment`'s docblock records the test D-23 was decided by --
 * whether anything in the product would read a segment the operator is not
 * looking at when they create it -- and answers it by naming `SendBlast`,
 * `BlastController::edit` and `BlastController::send`. All three are blast
 * readers and none of them read a segment. Leaving it that way would leave a
 * segment with exactly the readers §5 calls "a filter with a table under it".
 *
 * **What (a) is NOT refused for is code duplication, and that argument is spent
 * rather than won.** The plan recorded (a)'s cost as "two narrowing mechanisms
 * that share no code". D-29 already paid it: `App\Supporters\PostcodeNarrowing`
 * has three call sites -- this module's audience, the supporter list and the
 * export -- so the two mechanisms have shared their matching rule since Step 3.
 * What (a) still costs is a campaign retyping one aim into ten messages, which
 * is a product claim and is the one made above.
 *
 * **(c) is refused on three counts, and none of them is elegance.** It makes
 * every one-off aim a named object an operator has to create and later tidy;
 * `SegmentController::index()` records its own trigger as "the first thing that
 * creates segments other than an operator naming one by hand", and (c)'s data
 * migration would *be* that thing, firing a trigger this phase wrote against
 * itself in the commit that wrote it; and converting a frozen aim on a row into
 * a pointer at a mutable object would hand D-27's hazard to every blast already
 * sent, none of which opted into it. Under (b) a blast written before this
 * migration keeps saying exactly what it was aimed at, forever, because nothing
 * about its row changes.
 *
 * **So there is no data migration here, and that is the shape of the answer
 * rather than a convenience.** Nothing moves; one nullable column arrives.
 *
 * **What this does not decide, and must not be read as deciding.** Whether
 * editing or deleting a segment may change what an already-committed blast
 * reached is D-27, owned by Step 5. This migration creates that exposure and
 * names it rather than closing it: `SendBlast` re-reads the rule when the job
 * runs, so a blast queued against a segment edited in the meantime goes to a
 * different set of people. The only part of it forced into this step is the
 * foreign key's on-delete behaviour, decided below because a foreign key cannot
 * be added without deciding it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blasts', function (Blueprint $table) {
            // The pointer, placed beside the rule it is an alternative to so
            // that a reader of this table sees the two as one question.
            //
            // **Restricted on delete, and this is the one line here where the
            // obvious choice is a landmine.** Both of this table's other
            // foreign keys are `nullOnDelete`, and copying that would be wrong
            // in a way nothing would report: null is the *widening* branch of
            // this module's audience rule, so deleting a segment would silently
            // turn a draft blast aimed at three hundred people into one aimed
            // at every supporter the campaign may contact. `cascadeOnDelete` is
            // worse again -- it would delete the record of a message already in
            // other people's inboxes. So the database refuses the deletion, and
            // fails closed. What that refusal looks like to an operator is
            // owed by the deletion path and is not in this migration.
            //
            // No `->index()`, matching `operator_id` and `queued_by`, neither
            // of which has one: PostgreSQL indexes the referenced side of a
            // foreign key and not the referencing side, and this table is
            // bounded by human effort rather than by a file, which is the same
            // measurement `BlastController::index()` records for its own lack
            // of pagination.
            $table->foreignId('segment_id')->nullable()->after('body')
                ->constrained('segments')->restrictOnDelete();
        });

        // **An aim is one thing, so a row cannot hold two of them.**
        //
        // The alternative was precedence in application code -- "the segment
        // wins if both are set" -- and it is refused for the reason this table
        // already refuses a draft that carries a `queued_at`: a rule about
        // which of two columns to believe is a rule some future reader gets
        // wrong, and the thing it gets wrong is who a message goes to. There is
        // no unsending. Making the state unrepresentable costs one statement
        // and removes the question.
        //
        // Note what it deliberately does NOT forbid: both columns null, which
        // is the ordinary blast to everybody the campaign may contact and is
        // this module's only widening branch.
        //
        // Raw DDL for the fourth time in this project, and forced in the same
        // way the other three were rather than chosen for symmetry with them --
        // Blueprint has no check() method, so a check constraint cannot be
        // expressed through the schema builder at all.
        //
        // Written through the schema builder's own connection rather than
        // DB::statement(), so the constraint cannot land anywhere other than
        // the table it was just added to: under tenancy the default connection
        // is a moving target, and one that landed centrally would leave every
        // campaign's blasts quietly unconstrained.
        Schema::getConnection()->statement(
            'alter table "blasts" add constraint "blasts_aimed_one_way_only" '
            .'check (segment_id is null or postcode_prefixes is null)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Dropped before the column, because PostgreSQL will not drop a column
        // a constraint still depends on -- which is true of the check
        // constraint as well as of the foreign key, so both go first.
        Schema::getConnection()->statement(
            'alter table "blasts" drop constraint "blasts_aimed_one_way_only"'
        );

        Schema::table('blasts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('segment_id');
        });
    }
};
