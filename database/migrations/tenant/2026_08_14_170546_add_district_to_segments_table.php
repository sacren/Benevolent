<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A segment may narrow by one congressional district instead of by ZIP code
 * prefixes (D-37, amending D-24).
 *
 * **What is stored is the seat's name -- `MA-07` -- and nothing derived from
 * it.** Three other shapes were available and each is refused on a reason that
 * outlives this commit.
 *
 *   - **Not the district's ZIP codes.** Copying them onto the segment would be
 *     a cache of the relation that ships with the code (D-34), and it would go
 *     wrong at exactly the deploy that ships a new Congress's map -- which is
 *     D-35's reason for refusing a district column on `supporters`, one table
 *     along. A segment named for MA-07 would go on narrowing to the ZIP codes
 *     MA-07 used to hold.
 *   - **Not a Congress beside the seat.** Only one Congress's relation ships at
 *     a time, so a segment naming the 119th could not be honoured once the
 *     120th replaced it. The seat keeps its name across Congresses and the
 *     surfaces name the Congress they read it against (D-43), which is how the
 *     campaign's own seat is stored (D-40).
 *   - **Not "the campaign's seat" as a rule of its own.** A segment following
 *     the seat would change its audience whenever `campaign:seat` recorded a
 *     different one -- an audience moved by a console act no operator in the
 *     campaign performed (D-36). A segment names a district; the form may offer
 *     the campaign's seat as the district to name.
 *
 * So what a district segment reaches can change without the segment changing,
 * when a release ships a different relation. For a segment that is the point
 * of naming a district rather than a list of ZIP codes. What a *committed
 * blast* aimed that way holds when it happens is D-38's, and no blast can be
 * aimed at a district segment until it is answered: the blast form refuses one,
 * and App\Blasts\BlastAudience reaches nobody through one. (**D-38 was
 * answered at Step 6** -- a committed blast freezes the ZIP codes the relation
 * claimed, in `blasts.committed_zip_codes` -- and the last clause is corrected
 * with it: that class resolves a draft's district segment live, through
 * App\Segments\SegmentNarrowing. The form still refuses the aim until the
 * statement that commits a blast writes the freeze.)
 *
 * **`postcode_prefixes` becomes nullable, and a check constraint takes over the
 * guarantee NOT NULL used to give.** NOT NULL was there so that a segment
 * always names somewhere (D-24): "a segment that narrows nothing is not a
 * segment". A district segment has no prefixes, so the column has to admit
 * null, and `segments_narrow_one_way_only` restates the guarantee in the form
 * it now has to take -- exactly one of the two rules, never both and never
 * neither. It is `blasts_aimed_one_way_only` in the segment's own table, and
 * like that constraint it is raw DDL because the schema builder has no check
 * constraints to compile.
 *
 * The widening D-24's NOT NULL also stood guard over is untouched by this: null
 * means "everybody this campaign may contact" only on `blasts.postcode_prefixes`,
 * and BlastAudience draws that branch on the blast's own two columns, never on
 * a segment's.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            // A seat as people write it, `MA-07` or `AK-AL`, read back through
            // App\Districts\Seat::parse() against the relation this release
            // ships -- so a seat that relation does not name reads as no seat,
            // and narrows to nobody rather than to anybody.
            $table->string('district')->nullable()->after('postcode_prefixes');

            $table->json('postcode_prefixes')->nullable()->change();
        });

        Schema::getConnection()->statement(
            'alter table "segments" add constraint "segments_narrow_one_way_only" '
            .'check ((postcode_prefixes is null) <> (district is null))'
        );
    }

    /**
     * Reverse the migrations.
     *
     * Refuses rather than destroys when a district segment exists: restoring
     * NOT NULL fails on a row with no prefixes, and deleting the row to make
     * room would take with it a narrowing somebody named.
     */
    public function down(): void
    {
        Schema::getConnection()->statement(
            'alter table "segments" drop constraint "segments_narrow_one_way_only"'
        );

        Schema::table('segments', function (Blueprint $table) {
            $table->json('postcode_prefixes')->nullable(false)->change();
            $table->dropColumn('district');
        });
    }
};
