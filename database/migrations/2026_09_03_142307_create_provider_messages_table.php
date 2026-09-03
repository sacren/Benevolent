<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which campaign a provider's message id belongs to (D-86).
 *
 * **The first central table that holds per-send data, and the moment DEC-1's
 * boundary moves.** A notice from the provider names one message by SES's own
 * `mail.messageId` and nothing else that joins; each campaign is a separate
 * database, and a cross-database query is refused, so the only alternative to
 * this table is asking every campaign in turn for every notice -- measured at
 * about 18 ms a campaign, against 0.5 ms for one lookup here, and paid in full
 * by every notice that matches nothing. Criterion 7 forbids that walk outright,
 * because it enters every campaign for each notice.
 *
 * **It holds a key and a campaign, and nothing else.** No address, no name,
 * no blast and no supporter: the copy that the id names is found inside the
 * campaign it resolves to, where `blast_recipients.provider_message_id` holds
 * the same value. A row here says only that this campaign sent a message the
 * provider calls by this name.
 *
 * **Deleting a campaign removes its rows**, by the same cascade `domains`
 * uses, so a campaign's sending history does not outlive the campaign
 * centrally. The index on `tenant_id` is what keeps that cascade from reading
 * the whole table: PostgreSQL does not index the referencing side of a foreign
 * key on its own, and this table grows by one row per copy sent, across every
 * campaign (retention: D-90).
 *
 * No `updated_at`: a row is written once at send time and never changed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('provider_messages', function (Blueprint $table): void {
            $table->string('provider_message_id')->primary();
            $table->string('tenant_id')->index();
            $table->timestamp('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_messages');
    }
};
