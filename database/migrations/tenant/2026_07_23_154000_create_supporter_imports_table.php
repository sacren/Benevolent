<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt at getting an existing list into a campaign.
 *
 * Lives in the tenant migration set for the same reason `supporters` does
 * (D-1): the file belongs to one campaign, the people in it belong to one
 * campaign, and the record of what the import did is a campaign's own account
 * of its own data.
 *
 * **This table exists because the queue cannot report to an operator.** A
 * queued job returns nothing to the request that dispatched it, and when it
 * fails the row it leaves behind lands in the *central* `failed_jobs` table,
 * which no campaign surface reads and which cannot even say whose work failed
 * (Phase 0 Step 13 measured that the row carries no campaign column and the
 * question is one about payloads). Without a record here, an operator uploads a
 * file and then learns nothing at all -- not that it finished, not that it
 * failed, not why. So the campaign keeps its own account, and the job writes
 * the outcome onto it from inside the campaign, which it can do because a job's
 * failed() hook still runs in campaign context (measured; the JobFailed
 * listener that writes centrally is what runs after the revert, not this).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supporter_imports', function (Blueprint $table) {
            $table->id();

            // Who uploaded the file. Nullable and nulled on delete rather than
            // cascading, because the record of what happened to the campaign's
            // list must outlive the operator who started it -- an import that
            // added four thousand people is not undone by that person leaving.
            //
            // This is not the audit trail and does not reopen D-7. D-7 asked
            // whether a *supporter* change is a statement about authority and
            // answered no; this is one column on the import's own record,
            // answering "who ran this file", which is the first question anyone
            // asks about a batch that went wrong and which nothing else can
            // answer now that the trail deliberately does not.
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            // What the operator called the file, kept so they can recognise
            // which upload this record is about. Distinct from where it was
            // stored, which is ours and is never shown.
            $table->string('original_filename');

            // Relative to the `local` disk, which the filesystem bootstrapper
            // has already rooted at this campaign's own storage tree -- measured
            // as a separate directory per campaign rather than a shared one, so
            // one campaign's uploaded list is not reachable from another even by
            // path.
            $table->string('stored_path');

            // The file's own header row, read once at upload and kept so the
            // mapping form can offer the operator the columns their file
            // actually has. Reading them is not sniffing: nothing here decides
            // what a column *means*, which is the operator's to state.
            $table->json('headers');

            // Null until the operator says which column is which. There is no
            // default and no guess: a mapping this application invented would be
            // the fabrication the whole name design exists to prevent.
            $table->json('mapping')->nullable();

            // Deliberately the literal rather than ImportStatus::default(). A
            // migration must produce the same schema whenever it runs, and under
            // database-per-tenant it runs again for every campaign at whatever
            // date that campaign is provisioned -- so a default read out of
            // application code would give campaigns created after an edit a
            // different column default from the ones already provisioned. A test
            // pins the literal and the enum to each other instead, exactly as
            // the supporters table's status column does.
            $table->string('status')->default('awaiting_mapping');

            // What the run did, which is the whole of what an operator sees
            // afterwards. Counted separately because "we added 900 and corrected
            // 3,100" and "we added 3,100 and corrected 900" are very different
            // things to have done to a list, and a single total tells neither.
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('supporters_added')->default(0);
            $table->unsignedInteger('supporters_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);

            // Why it stopped, in the operator's own campaign rather than in a
            // central table they cannot see. Text rather than string because an
            // exception message has no length anyone controls.
            $table->text('failure_reason')->nullable();

            // Set once, when the run reaches a state it will not leave. Its
            // absence is what makes an import still in flight, which is the
            // question any surface reporting progress has to ask.
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supporter_imports');
    }
};
