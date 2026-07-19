<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people a campaign is trying to reach.
 *
 * Lives in the tenant migration set for the same reason operators and audit
 * entries do (D-1): a supporter belongs to one campaign, so they belong in that
 * campaign's own database. A central supporters table would pool every
 * campaign's list into one place and hand any reader of it another campaign's
 * people — the same inversion the audit trail's migration warns about, with far
 * more personal data behind it, and undetectable from inside any one campaign
 * because a shared table looks exactly like a working list.
 *
 * The column set is deliberately thin. It holds what a campaign needs to reach
 * a person and to tell one person from another, and nothing that merely sounds
 * useful: no acquisition source, no phone, no tags, no notes, no address lines,
 * no custom fields.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supporters', function (Blueprint $table) {
            $table->id();

            // Three name columns, all nullable, because the two obvious schemas
            // destroy information in opposite directions. A single column loses
            // the boundary whenever the source supplied one -- and nearly every
            // real advocacy export does, so that is the common case rather than
            // a corner. Split columns force a boundary to be invented when the
            // source gave one string, which fails outright on a mononym, on a
            // name with no clean split, and on family-name-first presentation.
            //
            // So neither is derived from the other. `name` is the display
            // string: as the source gave it, or the join of the parts below.
            // `given_name` and `family_name` are provenance -- what we were
            // actually told. Nothing splits a name and nothing infers a part, so
            // a single-string source truthfully leaves both parts null, meaning
            // *we were never told* rather than *we guessed*.
            //
            // All three are nullable because real lists carry rows with an
            // address and no name at all: a petition widget that asked only for
            // an email, a partner list swap. Requiring a name would force an
            // import either to reject a perfectly contactable supporter or to
            // fabricate one, and fabrication is the thing this design exists to
            // prevent, not to relocate.
            $table->string('name')->nullable();
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();

            // The identity (D-8), and the one column here that is not nullable.
            // A supporter with no name is still contactable; a supporter with no
            // address is neither contactable nor identifiable, and this module
            // carries no second channel to reach them by. Relaxing this later is
            // one migration; admitting unidentifiable rows now and tightening
            // afterwards means deciding by hand which of them were the same
            // person.
            $table->string('email');

            // Stored exactly as the source gave it: no validation, no
            // normalization, no country column. Validation would be wrong for
            // most of the world, and normalizing on write is skipped for the
            // same reason the name parts are never joined away -- a normalized
            // value is recoverable from the raw one at any time, while the raw
            // is not recoverable from the normalized. Whoever builds
            // segmentation decides how to match.
            $table->string('postcode')->nullable();

            // Deliberately the literal rather than SubscriptionStatus::default().
            // A migration must produce the same schema whenever it runs, and
            // under database-per-tenant it runs again for every campaign at
            // whatever date that campaign is provisioned -- so a default read
            // out of application code would give campaigns created after an edit
            // a different column default from the ones already provisioned:
            // schema that varies between campaigns on one deploy, visible only
            // by comparing live databases. A test pins the literal and the enum
            // to each other instead, and fails the moment either moves alone.
            //
            // The direction matters as much as the value. A creator that names
            // no status produces a supporter the campaign may contact, which is
            // the assertion the operator made by importing them at all.
            $table->string('subscription_status')->default('subscribed');

            // created_at is when this person joined the list, which is the only
            // history this module keeps about them.
            $table->timestamps();
        });

        // D-8, in the only form PostgreSQL will accept it.
        //
        // "Unique within a campaign" needs no campaign column and no composite
        // key: the whole table is one campaign's, so uniqueness of the table is
        // uniqueness within the campaign. Two campaigns may hold the same
        // address, and should -- one person may support both.
        //
        // The index is on lower(email) rather than on the column, because a list
        // that treats `Jean@Example.test` and `jean@example.test` as two people
        // has failed at exactly the variation real exports are full of. Casing
        // is not part of who someone is, so it is not part of the key.
        //
        // Raw DDL because the schema builder cannot express this, measured both
        // ways: `unique()` compiles to ALTER TABLE ... ADD CONSTRAINT, and
        // PostgreSQL rejects an expression in a unique *constraint* (42601);
        // passing an algorithm routes it through CREATE UNIQUE INDEX and then
        // promotes it to a constraint, which is rejected again (42809, "index
        // contains expressions"). Only a plain unique index takes an expression.
        //
        // Written through the schema builder's own connection rather than
        // DB::statement(), so the index cannot go anywhere other than where the
        // table just went -- under tenancy the default connection is a moving
        // target, and an index that landed centrally would leave every
        // campaign's list quietly unconstrained.
        Schema::getConnection()->statement(
            'create unique index "supporters_email_unique" on "supporters" (lower(email))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The index belongs to the table and goes with it.
        Schema::dropIfExists('supporters');
    }
};
