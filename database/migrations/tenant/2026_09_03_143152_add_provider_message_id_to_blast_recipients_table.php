<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider calls this copy (D-86, D-87).
 *
 * A notice from SES names the message it is about by SES's own
 * `mail.messageId`, which the transport also stamps on the sent message as
 * `X-SES-Message-ID` -- measured equal in all four notices Step 2 captured.
 * Kept here, on the copy it names, so a notice resolved to this campaign by
 * the central `provider_messages` table finds its copy with one indexed read.
 *
 * **Nullable, and null means the provider gave no id.** Every copy sent
 * before this column existed, every copy that failed, and every copy sent
 * through a mailer that returns no id (the `array` and `log` mailers) holds
 * none, so the column never claims a copy is matchable when it is not. Unique,
 * because one id names one message; nulls do not conflict.
 *
 * **It corrects the same sentence the `link_token` migration corrected.** An
 * SES message id is not a name or an address, but it is a join key back to
 * one: the provider's records and every notice it sends hold the id beside
 * the address it went to. So it must not outlive the supporter it can name,
 * any more than the link token may (D-49), and the trigger that forgets the
 * token on an erasure is given this column to forget as well.
 *
 * **The trigger's function is replaced rather than a second trigger added.**
 * One erasure, one rule about what a keyless row may hold, written in one
 * place: two triggers on the same event would fire in name order and could be
 * dropped one at a time. The frozen migration that created the function stays
 * as it is; this one owns its body from here, and `down()` puts the original
 * back. Raw DDL for the reason that migration records: the schema builder has
 * no trigger or function API.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No default, so no existing row is filled and the table is not
        // rewritten -- re-measured for this column rather than carried from
        // `link_token`: on 100,000 rows the table's file on disk kept its
        // filenode and its size through both statements. The unique
        // constraint reads the table to build its index; it does not copy it.
        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->string('provider_message_id')->nullable()->unique();
        });

        Schema::getConnection()->statement(
            'create or replace function "blast_recipients_forget_link_token"() returns trigger language plpgsql as $$ '
            .'begin new."link_token" := null; new."provider_message_id" := null; return new; end $$'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Back to the body the link-token migration gave it, and before the
        // column goes rather than after. PL/pgSQL resolves the column only when
        // the trigger fires, so the order would not fail here: measured, a
        // function left naming a dropped column refuses every later erasure
        // with `record "new" has no field "provider_message_id"`.
        Schema::getConnection()->statement(
            'create or replace function "blast_recipients_forget_link_token"() returns trigger language plpgsql as $$ '
            .'begin new."link_token" := null; return new; end $$'
        );

        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->dropUnique(['provider_message_id']);
            $table->dropColumn('provider_message_id');
        });
    }
};
