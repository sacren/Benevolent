<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The campaign's record of who changed what, and when.
 *
 * Lives in the tenant migration set for the same reason operators do (D-1): an
 * audit entry describes something that happened inside one campaign, so it
 * belongs in that campaign's own database. A central audit table would pool
 * every campaign's history into one place — the exact inverse of the isolation
 * this platform is built on, and the failure mode worth being loudest about,
 * because a shared history table looks like a working audit trail right up
 * until someone reads another campaign's out of it.
 *
 * There are deliberately no foreign keys on `actor_id` or `subject_id`. An
 * audit entry has to outlive the row it describes — the entry recording that an
 * operator was removed is written as that operator ceases to exist — so a
 * constraint here would delete the evidence along with the subject, or refuse
 * the deletion outright. The labels below are what make the trail readable
 * afterwards.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();

            // Deliberately the column type rather than an enum constrained to
            // AuditEvent's cases. A migration must produce the same schema
            // whenever it runs, and under database-per-tenant it runs again for
            // every campaign at whatever date that campaign is provisioned --
            // so a constraint built from application code would give campaigns
            // created after an edit a different schema from the ones already
            // provisioned, visible only by comparing live databases. The enum
            // stays the application's business; a test pins the two together.
            $table->string('event')->index();

            // What was acted upon. The label is captured at the time of writing
            // and never refreshed, so the trail still names the operator after
            // the row it points at is gone.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label');

            // Who did it, when that is knowable. Nullable because it is
            // genuinely not always known: an operator registering on an open
            // campaign is not acting on anyone's authority, so no actor granted
            // them what they got. Recording null there is the honest answer and
            // is itself the interesting one.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            // What changed, as attribute => {from, to}. Null where nothing
            // changed in that sense, such as an operator being removed.
            $table->json('changes')->nullable();

            // No updated_at. An audit entry is a statement about a moment and is
            // never revised; a column inviting revision would undermine the one
            // property the table exists to have.
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
