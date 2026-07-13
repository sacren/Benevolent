<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every operator a role within their campaign.
 *
 * Lives in the tenant migration set because operators do (D-1): a role says
 * what someone may do inside one campaign, so it belongs in that campaign's
 * own database alongside the identity it describes. There is deliberately no
 * central equivalent — a role has no meaning outside the campaign that
 * granted it.
 *
 * The column carries a database-level default so that existing rows are valid
 * the moment it is added, and so that any future creator which forgets to set
 * a role produces the *least* privileged operator rather than an invalid or
 * an over-privileged one. Registration overrides it deliberately.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately the literal value rather than OperatorRole::default().
            // A migration must produce the same schema whenever it runs, and
            // under database-per-tenant it runs again for every campaign, at
            // whatever date that campaign is provisioned. Reading the default
            // out of application code would mean that editing the enum silently
            // gave campaigns created afterwards a different column default from
            // the ones already provisioned -- schema that varies between
            // campaigns on one deploy, visible only by comparing live databases.
            //
            // The two are kept in agreement by a test instead, which fails the
            // moment either side moves without the other. That makes the drift
            // loud at the point it is introduced and leaves this file frozen.
            $table->string('role')->default('staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
