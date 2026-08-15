<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a blast aimed at a congressional district freezes what it was aimed at
 * (D-38).
 *
 * **D-27 froze a rule; a district is not one.** `committed_prefixes` holds what
 * a segment said at the moment the campaign committed the blast, and a frozen
 * `["902"]` names the same people forever because a postcode prefix is a
 * literal. A segment may now narrow by seat instead (D-37), and a frozen
 * `MA-07` would name whatever the relation shipping with the release in force
 * at send time says it names -- which is a different set after any release that
 * replaces `resources/data/cd119-zcta.json`, and with no queue worker running
 * anywhere (Finding A) a committed blast can sit in `queued` across as many
 * releases as it takes for one to start. So what is frozen for a district is
 * the ZIP codes themselves, and they need somewhere to be.
 *
 * **A second column rather than the one that already holds a frozen aim, and
 * the reason is which reader replays it.** `committed_prefixes` is read by
 * App\Supporters\PostcodeNarrowing, which compares leading characters and
 * therefore reaches a stored `02141abc` through `02141`. A district's ZIP codes
 * are read by App\Districts\DistrictNarrowing::toZipCodes(), which is
 * DistrictClaim's rule and refuses to claim anything for that value. Both
 * meanings in one column would leave the send path picking its reader from
 * something other than the value it is about to replay -- the segment's kind,
 * which is precisely what a committed blast is not allowed to read (D-27) --
 * and picking wrong is a message going to people the campaign never committed
 * it to. Two columns make the reader a property of where the value is written.
 *
 * **The name says ZIP codes where the identifiers around it say postcode, and
 * D-41 is not reopened.** That decision keeps `postcode` on the existing
 * identifiers because it is the generic noun and a ZIP code is the US instance
 * of it; nothing is renamed here. What this column holds has no generic
 * reading: every element is five digits or the narrowing refuses the whole list
 * (`DistrictNarrowing::ZIP_CODE`), and `toZipCodes()` is its only reader.
 * Calling it `committed_postcodes` would invite the prefix reader, which is the
 * one mistake this column exists to prevent.
 *
 * **The constraint counts the frozen rules rather than testing one for null,
 * and the amendment that reads as obvious leaves a hole.** Written
 * `(num_nonnulls(committed_prefixes, committed_zip_codes) = 1) = (status <>
 * 'draft' and segment_id is not null)`, a *draft carrying both* passes: the
 * left side is false because two is not one, the right side is false because it
 * is a draft, and false equals false. Measured on this server rather than
 * reasoned about -- that expression returns true for such a row, and the
 * counting form below returns false for the same one. Counting says what is
 * actually meant: a committed segment-aimed blast carries exactly one frozen
 * rule, and everything else carries none.
 *
 * **No existing row can be refused by the change, and that is a property rather
 * than a headcount.** Wherever the new column is null the counting form and the
 * old biconditional agree for every combination of the other two -- checked as
 * a truth table on this server -- so no row written before this migration can
 * fail the constraint it is validated against as it is added. The only campaign
 * holds zero blasts as well, but that is the weaker of the two reasons and the
 * one that stops being true.
 *
 * **Nothing reads this column and nothing writes it, and that is the whole of
 * this commit.** A blast still cannot be aimed by district at all --
 * ComposeBlastRequest refuses such a segment, BlastController does not offer
 * one, and BlastAudience answers one with nobody -- and this constraint leaves
 * a committed district-aimed blast exactly as impossible as it was, since both
 * frozen columns would be null and none is not one. The place the freeze will
 * land is added before the statement that lands it, so that the writer cannot
 * ship without somewhere to write.
 *
 * **Raw DDL for the seventh time in this project, and forced the same way the
 * other six were** rather than chosen: Blueprint has no check() method, so a
 * check constraint cannot be expressed through the schema builder at all.
 * Written through the schema builder's own connection rather than
 * DB::statement(), so it cannot land anywhere other than the table it was just
 * altered on -- under tenancy the default connection is a moving target, and
 * one that landed centrally would leave every campaign's blasts quietly
 * unconstrained.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blasts', function (Blueprint $table) {
            // Placed beside the frozen rule it is the alternative to rather
            // than at the end of the table, because the two answer the same
            // question about the same blast and differ only in which reader
            // replays them.
            //
            // **Absent from the model's `#[Fillable]`**, with `status`,
            // `queued_at`, `finished_at`, `failure_reason` and
            // `committed_prefixes`, and for their reason: a form able to set
            // this could re-aim a message that has already gone out.
            //
            // Nullable because every blast but one kind has nothing to freeze
            // here -- a draft has not committed, a blast carrying its own
            // prefixes has its rule on its own row, and a segment of prefixes
            // freezes those instead.
            $table->json('committed_zip_codes')->nullable()->after('committed_prefixes');
        });

        // Dropped and rewritten rather than added alongside, because a second
        // constraint could only repeat the first's left-hand side and the two
        // would then disagree the moment either was edited. The name is kept:
        // it is still the same rule about the same moment, now counting two
        // columns instead of one.
        Schema::getConnection()->statement(
            'alter table "blasts" drop constraint "blasts_committed_aim_is_frozen"'
        );

        Schema::getConnection()->statement(
            'alter table "blasts" add constraint "blasts_committed_aim_is_frozen" '
            .'check (num_nonnulls(committed_prefixes, committed_zip_codes) '
            ."= case when status <> 'draft' and segment_id is not null then 1 else 0 end)"
        );
    }

    /**
     * Reverse the migrations.
     *
     * Refuses rather than destroys when a district-aimed blast has already
     * frozen its ZIP codes: the old constraint demands `committed_prefixes` on
     * exactly the rows this column was written for, so restoring it fails with
     * a check violation while the row is still there to be looked at. Dropping
     * the column first to make room would silently take with it the record of
     * what a message the campaign has already sent was aimed at, which is the
     * one thing no later migration restores.
     */
    public function down(): void
    {
        Schema::getConnection()->statement(
            'alter table "blasts" drop constraint "blasts_committed_aim_is_frozen"'
        );

        Schema::getConnection()->statement(
            'alter table "blasts" add constraint "blasts_committed_aim_is_frozen" '
            ."check ((committed_prefixes is not null) = (status <> 'draft' and segment_id is not null))"
        );

        Schema::table('blasts', function (Blueprint $table) {
            $table->dropColumn('committed_zip_codes');
        });
    }
};
