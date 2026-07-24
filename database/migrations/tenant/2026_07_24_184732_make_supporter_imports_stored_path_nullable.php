<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a campaign say that it no longer holds the file an import came from.
 *
 * The uploaded CSV is the one place a campaign's supporters live that no
 * deletion path reaches. Deleting a supporter removes their row; the file that
 * named them keeps naming them, in the clear, along with every row the import
 * skipped for want of a usable address — people who were never in the table at
 * all and so can never be reached by deleting one. That is what makes retention
 * the erasure here rather than a tidiness measure.
 *
 * Which leaves this column saying something untrue the moment a file is
 * removed. `stored_path` is not merely a pointer: an import record that still
 * names a path is a campaign claiming to hold that file. Nulling it is the
 * campaign's own statement that it does not — the answer an operator needs when
 * somebody asks what is still held about them.
 *
 * The record itself is kept forever and is deliberately untouched here. Measured
 * before this was designed: an import row carries the operator's filename, the
 * counts, the mapping and the file's header row, and the header row is column
 * names rather than people. So the account of what happened to the campaign's
 * list holds no supporter's details and has no reason to expire. The file does.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supporter_imports', function (Blueprint $table) {
            $table->string('stored_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * **Reverting loses information, and it cannot not.** Once a file has been
     * pruned there is no path to put back, so the column has to be filled with
     * something before it can refuse nulls again — and the empty string is the
     * least dishonest filler available, since it names no file. What is lost is
     * the distinction between "we never had one" and "we had one and no longer
     * do", which is the whole thing this migration exists to record.
     *
     * Written with the query builder rather than the model, because a migration
     * is frozen and a model is not: a scope, an observer or a global scope added
     * to SupporterImport years from now would silently change what this touches
     * when it runs again on a fresh campaign.
     */
    public function down(): void
    {
        DB::table('supporter_imports')->whereNull('stored_path')->update(['stored_path' => '']);

        Schema::table('supporter_imports', function (Blueprint $table) {
            $table->string('stored_path')->nullable(false)->change();
        });
    }
};
