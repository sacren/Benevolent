<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order the supporter list is actually read in.
 *
 * Every load of the list page orders by arrival, newest first, with the id
 * breaking ties so the order is total — and until now nothing indexed that, so
 * PostgreSQL sorted the whole table on every request. Measured on 50,000
 * supporters with `explain (analyze)`: a sequential scan and sort costs
 * 29.087 ms, and an index scan costs 6.670 ms.
 *
 * **The columns are declared ascending although the query reads them
 * descending, and that is deliberate rather than an oversight.** A btree is
 * scannable in both directions, so this one index serves both orders. Measured
 * both ways rather than assumed: against the identical query, the ascending
 * index produces `Index Scan Backward` at 6.670 ms and an explicitly
 * `(created_at desc, id desc)` index produces `Index Scan` at 6.519 ms — the
 * same plan shape and the same time to within noise. Since the two are
 * equivalent, the form the schema builder can express wins, because the
 * alternative is raw DDL.
 *
 * That is the whole reason this migration looks unlike the one that created the
 * table. There the unique index had to be written as a raw statement, because
 * `lower(email)` is an expression and the builder cannot express one at all.
 * The exception was forced there and is not forced here, so it is not repeated
 * — a raw statement copied for symmetry would carry the earlier migration's
 * cost with none of its reason.
 *
 * Both columns are needed. `created_at` alone leaves the tie-break unindexed,
 * and supporters imported in the same second are common rather than a corner:
 * one file writes thousands of rows inside one transaction with one timestamp.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supporters', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supporters', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
