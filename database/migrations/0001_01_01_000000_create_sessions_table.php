<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions are shared web infrastructure rather than campaign data, so they
 * stay in the central database even though the operator who owns a session
 * lives in a campaign's own database.
 *
 * This is what remains of the scaffold's combined migration, which also created
 * `users` and `password_reset_tokens`. Both now live in
 * database/migrations/tenant/ instead, so each campaign owns its operators and
 * central owns none.
 *
 * `user_id` is an index rather than a foreign key, and has to stay that way: an
 * operator id is only meaningful inside one campaign's database, so two
 * campaigns can each hold an operator 1 and this column cannot tell them apart.
 * Nothing here reads it -- Laravel's database session driver writes it and this
 * application has no other consumer.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
