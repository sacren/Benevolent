<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a blast could not say about its own send.
 *
 * **`queued_by` — who committed the message to sending, which is not who wrote
 * it.** `blasts.operator_id` was added at Step 1 with a docblock calling it
 * "who wrote this, which is the first question anyone asks about a message that
 * went out wrong". That was true when it was written and Step 2 made it false:
 * Staff hold `EditBlasts` and not `SendBlasts`, so the ordinary shape of this
 * product is that one person drafts a blast and a different person commits it.
 * Authorship then answers a question nobody asked, and the act that cannot be
 * taken back is recorded nowhere at all.
 *
 * **This is D-17's answer, and deliberately not the audit trail's.** The
 * argument for recording a send in the trail is real -- a send is irreversible,
 * it speaks in the campaign's name to people outside the campaign, and Step 2
 * made it an authority not everyone holds. It loses on this project's own
 * precedent rather than on its merits: `ExportSupporters` takes the whole list
 * out of the campaign and `DeleteSupporters` is irreversible, both are already
 * Owner-only, and D-7 deliberately records neither. Adding a send while those
 * stay out is precisely the sequence of individually justified increments
 * Blueprint §5 warns produces a worse version of the package nobody chose. And
 * the trail's stated subject is *changes to* who may act with what authority; a
 * send is an exercise of authority rather than a change to it.
 *
 * So the fact is recorded where it belongs -- on the record of the thing that
 * happened -- and deferrals 9 and 10 do not fire, because the trail's volume is
 * exactly what it was.
 *
 * Nulled on delete rather than cascading, for the reason `operator_id` is: the
 * record of what left this campaign must outlive whoever sent it, and far more
 * so here, because deleting the row would not recall the message.
 *
 * **`failure_reason` — why a send stopped, where the operator can read it.**
 * `BlastStatus::Failed` existed from Step 1 with nowhere to say what happened,
 * so a campaign could see that its send had stopped and nothing else. This is
 * the same column `supporter_imports` carries and for the same reason: central
 * `failed_jobs` has no campaign column and no campaign surface reads it, so a
 * failure written only there is a failure the campaign cannot see.
 *
 * Per *blast*, not per recipient. One refused address is not a failed send --
 * `blast_recipients.failure_reason` holds those, one row each -- and this is
 * for what stopped the send as a whole.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blasts', function (Blueprint $table) {
            $table->foreignId('queued_by')->nullable()->after('operator_id')
                ->constrained('users')->nullOnDelete();

            $table->text('failure_reason')->nullable()->after('finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blasts', function (Blueprint $table) {
            // Dropped before the column, because PostgreSQL will not drop a
            // column a constraint still depends on.
            $table->dropConstrainedForeignId('queued_by');
            $table->dropColumn('failure_reason');
        });
    }
};
