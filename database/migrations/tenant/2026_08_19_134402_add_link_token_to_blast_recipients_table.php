<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a message calls itself in the one link it carries (D-46).
 *
 * Until now every message a person received carried the same link, because the
 * token in it was the supporter's. So the request that unsubscribes somebody
 * could not say which message prompted it, and attribution was missing from
 * the request rather than from the write. This column is a second credential
 * of the same kind as `supporters.unsubscribe_token` -- random, stored, and
 * scoped by the campaign's own database, which is what D-16(a) chose a stored
 * token for -- minted per copy instead of per person, so the link in an inbox
 * names one message to one supporter.
 *
 * **It corrects a sentence in the frozen `create_blast_recipients_table`
 * migration.** That file says this table "carries a foreign key and nothing
 * else about the person", and that is no longer the whole truth: a link token
 * is not a name or an address, but it is a credential that resolves to a
 * person while it exists, and Step 2 measured it to be a join key back to the
 * address in copies outside the schema -- the request log holds it with a
 * timestamp, and the `log` mailer writes it beside the address it mailed. The
 * trigger below is what keeps D-10's answer true anyway: after an erasure the
 * row still says a message went and no longer holds anything that could say to
 * whom. Migrations are frozen once run, so the correction lives here.
 *
 * **Nullable, and the default arrives in a second statement, because the
 * difference is the whole meaning of the column.** Measured on PostgreSQL
 * 18.1: a nullable column added *with* a volatile default in one statement
 * fills every existing row, so rows claimed before this migration would hold
 * tokens their messages never carried, and "non-null" would stop meaning "this
 * copy carried a per-recipient link". Added bare and then given its default,
 * the rows that predate it stay null, which is the true statement that the
 * message they record was sent when every link named a person. Nulls do not
 * conflict in a unique index, so any number of them sit here without
 * collision.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->uuid('link_token')->nullable()->unique();
        });

        // The second statement, and the one this migration exists to separate
        // from the first. The builder writes it rather than raw SQL: measured,
        // it emits `alter column ... type uuid, ... set default
        // gen_random_uuid()`, which fills no existing row and does not rewrite
        // the table -- on a thousand rows the table's file on disk was
        // unchanged, which matters for a campaign whose recipients number in
        // the hundreds of thousands.
        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->uuid('link_token')->nullable()->default(new Expression('gen_random_uuid()'))->change();
        });

        // **A token must not outlive the supporter it can name, and the schema
        // rather than the resolver is what says so (D-49).**
        //
        // `supporter_id` is nulled when a supporter is erased, which keeps the
        // blast's count honest while forgetting who (D-10). A token left
        // standing on that row would undo it: the request log and the `log`
        // mailer's copies both hold the token beside the address, so a
        // surviving token is a join key from a keyless row back to a person.
        //
        // **A check constraint was measured and refused.** Written as
        // `supporter_id is not null or link_token is null`, the foreign key's
        // own `SET NULL` violates it, so the erasure is refused outright --
        // and the error's DETAIL prints the token, which is a credential in a
        // message that gets logged. A BEFORE trigger runs before constraints
        // are checked and removes the value instead, which is the behaviour
        // that was wanted.
        //
        // **`insert or update`, not `update` alone.** The measured shape at
        // Step 2 was an update trigger, because an erasure is an update. But a
        // trigger that fires only on update leaves the same forbidden state
        // reachable by an insert: a row written with no supporter still takes
        // the column default, measured. Nothing in the application writes such
        // a row -- the claim always names a supporter -- and that is exactly
        // why the schema, rather than the one writer, should be what forbids
        // it.
        //
        // Raw DDL because the schema builder has no trigger API at all:
        // `Blueprint`, `Builder` and `PostgresGrammar` each contain zero
        // trigger methods. Issued through the builder's own connection rather
        // than DB::statement(), so it cannot land centrally while the table it
        // guards went to a campaign.
        Schema::getConnection()->statement(
            'create function "blast_recipients_forget_link_token"() returns trigger language plpgsql as $$ '
            .'begin new."link_token" := null; return new; end $$'
        );

        Schema::getConnection()->statement(
            'create trigger "blast_recipients_forget_link_token" '
            .'before insert or update of "supporter_id" on "blast_recipients" '
            .'for each row when (new."supporter_id" is null) '
            .'execute function "blast_recipients_forget_link_token"()'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The trigger goes before the column it writes to, and the function
        // after the trigger that calls it.
        Schema::getConnection()->statement('drop trigger if exists "blast_recipients_forget_link_token" on "blast_recipients"');
        Schema::getConnection()->statement('drop function if exists "blast_recipients_forget_link_token"()');

        Schema::table('blast_recipients', function (Blueprint $table): void {
            $table->dropUnique(['link_token']);
            $table->dropColumn('link_token');
        });
    }
};
