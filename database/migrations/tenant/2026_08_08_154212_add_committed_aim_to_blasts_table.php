<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a blast was aimed at when the campaign committed it (D-27).
 *
 * **D-27(a) resolved: the aim is frozen at the moment the campaign commits,
 * rather than the edit being refused.** Step 4 gave a blast a pointer at a
 * segment and named the exposure it created rather than closing it:
 * `SendBlast` reads the rule when the job runs, so a blast queued against one
 * narrowing can go out against another. This column is where the rule stops
 * moving.
 *
 * **The hazard is a duration and not an instant, which is what ruled the
 * alternative out.** `SendBlast` sets `$tries = 3` and `config/queue.php` sets
 * `retry_after` to 90 seconds, so any real send is released back to the queue
 * and re-entered -- and `handle()` calls `BlastAudience::for()` afresh on every
 * attempt. Measured: a send whose segment was widened between two attempts
 * delivered to a supporter who was never in the committed audience, because the
 * unique index on `blast_recipients` refuses a *duplicate* and says nothing
 * about an audience that grew. So a mechanism that refused the edit would have
 * to hold for the whole life of a send, through a check-then-write window with
 * nothing behind it -- where `SegmentController::destroy()`'s equivalent race is
 * backstopped by a foreign key and an edit has no such backstop. Freezing is
 * written inside the conditional update that already commits the blast, so it
 * is race-free by construction rather than by care.
 *
 * **It also keeps a segment correctable, which is the whole of D-23.** A
 * refusal would lock a segment for as long as a blast aimed at it sat
 * uncommitted-and-unsent -- and with no queue worker deployed anywhere
 * (Finding A), `queued` is a state nothing leaves, so "until the send
 * finishes" would mean "forever" in the environment this product actually runs
 * in. `SegmentController::destroy()` already shows what that costs: its refusal
 * tells the operator to re-aim the blast first, which `refuseCommitted()` makes
 * impossible for exactly the committed blast the refusal is protecting.
 *
 * **Why this is a column of its own rather than a write to `postcode_prefixes`,
 * and the database is what decided it.** The plan's candidate was "copy the
 * rule onto the blast at commit time". Attempted against a segment-aimed blast,
 * that write is refused: SQLSTATE 23514 against `blasts_aimed_one_way_only`,
 * the constraint Step 4 added to stop a row holding two aims. Nulling
 * `segment_id` to make room is worse -- it would destroy the record of which
 * segment a sent blast named, which `SegmentManagementTest` pins, and would
 * release the foreign key that stops that segment being deleted. So the frozen
 * rule cannot be an *aim*; it is the record of one, and it needs its own place.
 *
 * **This is not the convenience §7 criterion 5 forbids.** That prohibition is
 * about how a blast is *aimed* -- satisfying "a blast can point at a segment"
 * by copying instead of pointing, which would leave the two-mechanism cost this
 * phase exists to remove. A blast still points while it is a draft, and
 * re-aiming a segment still moves every draft aimed at it. The copy is taken at
 * the irreversibility boundary, which is a different act with a different
 * purpose.
 *
 * **No data migration, and that is measured rather than assumed.** The
 * constraint below validates every existing row as it is added, so a committed
 * segment-aimed blast predating this migration would refuse it. There are none:
 * the only campaign holds zero blasts, and `blasts.segment_id` itself is one
 * step old. Nothing moves; one nullable column arrives.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blasts', function (Blueprint $table) {
            // Placed after the two columns that hold an aim, because it is
            // neither of them: it is what one of them *said* at the moment the
            // campaign gave up the right to change it.
            //
            // **Deliberately absent from the model's `#[Fillable]`**, with
            // `status`, `queued_at`, `finished_at` and `failure_reason`, and for
            // their reason: a form able to set this could re-aim a message that
            // has already gone out, which is the one thing no later commit
            // repairs.
            //
            // Nullable because a draft has no committed aim and a blast
            // carrying its own prefixes needs none -- its rule already sits on
            // its own row, where nothing but this blast's own compose form can
            // reach it, and that form is refused once the blast is committed.
            // The only rule that can move under a committed blast is a
            // segment's, so that is the only one frozen here.
            $table->json('committed_prefixes')->nullable()->after('segment_id');
        });

        // **The state that would be ambiguous is made unrepresentable rather
        // than guarded by a comment**, which is the choice this table already
        // makes twice -- `blasts_draft_has_not_been_queued` and
        // `blasts_aimed_one_way_only` -- and it is worth more here than in
        // either, because the ambiguous row is one whose audience nobody can
        // name.
        //
        // A committed blast that points at a segment has a frozen rule; nothing
        // else has one. Written as a biconditional rather than as two
        // implications for the reason the draft/queued constraint is: the
        // half-written version passes for whichever direction its author was
        // thinking about, and the other direction is the one that ships.
        //
        // What it forbids in each direction is a real mistake rather than the
        // same one twice. A draft carrying a frozen rule is an aim recorded
        // before the campaign committed to it, which `BlastAudience` would then
        // prefer over the segment the operator is still editing -- a draft that
        // stopped following its own pointer. A committed segment-aimed blast
        // *without* one is the defect this whole column exists to prevent,
        // arriving through a write that forgot it: there would be nothing to
        // read, and the only safe reading of nothing is that the send reaches
        // nobody, which is a message silently not sent.
        //
        // Raw DDL for the fifth time in this project, and forced the same way
        // the other four were rather than chosen for symmetry: Blueprint has no
        // check() method, so a check constraint cannot be expressed through the
        // schema builder at all.
        //
        // Written through the schema builder's own connection rather than
        // DB::statement(), so it cannot land anywhere other than the table it
        // was just added to -- under tenancy the default connection is a moving
        // target, and one that landed centrally would leave every campaign's
        // blasts quietly unconstrained.
        Schema::getConnection()->statement(
            'alter table "blasts" add constraint "blasts_committed_aim_is_frozen" '
            ."check ((committed_prefixes is not null) = (status <> 'draft' and segment_id is not null))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Dropped before the column, because PostgreSQL will not drop a column
        // a constraint still depends on.
        Schema::getConnection()->statement(
            'alter table "blasts" drop constraint "blasts_committed_aim_is_frozen"'
        );

        Schema::table('blasts', function (Blueprint $table) {
            $table->dropColumn('committed_prefixes');
        });
    }
};
