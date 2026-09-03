<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider reported about each copy (D-87).
 *
 * **One column per kind of notice, because one copy can bring several kinds
 * and never two of the same.** Every copy measured received at most one
 * notice of each kind, while one id brought two kinds twice (a Delivery and a
 * Complaint; a Delivery and a Transient bounce), so the kinds are columns
 * beside each other rather than one status that a later notice would
 * overwrite. A repeated notice is the same fact delivered twice (AWS's FAQ),
 * so each column is written only while it is empty -- refused in the database
 * by the update's own condition rather than checked first and raced (D-88).
 *
 * **The times are the provider's, not the intake's.** Each is the event
 * timestamp the notice carries, so a notice SNS retried for a minute still
 * says when the bounce happened rather than when it was heard about. Kept to
 * the second, as `sent_at` is: SES gives milliseconds, and Laravel binds a
 * date as `Y-m-d H:i:s`, so the fraction is cut before it reaches the column.
 *
 * **Null means not known, and nothing is back-filled.** Every copy sent before
 * the intake existed, and every copy no notice has reached, holds nothing
 * here; the surface says *not known* rather than inferring delivery (§3).
 *
 * **None of this is about the person.** Like `sent_at`, these are facts about
 * a send that stay true after an erasure; the key that could name the reader
 * (`provider_message_id`) is the one the trigger forgets.
 *
 * Nullable with no default, the shape unit 1 measured: no existing row is
 * filled and the table is not rewritten.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_type')->nullable();
            $table->timestamp('complained_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->dropColumn(['delivered_at', 'bounced_at', 'bounce_type', 'complained_at']);
        });
    }
};
