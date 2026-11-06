<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retest linking + frozen comparison (first data slice of the retest feature).
 *
 *  - retest_of_report_id: nullable self-FK to reports.id. A report is a retest when
 *    this is set. nullOnDelete so FORCE-deleting the original nulls the link but never
 *    removes the retest (which also keeps its frozen snapshot regardless). Reports are
 *    soft-deleted, so an ordinary delete of the original doesn't touch the retest at all.
 *  - retest_comparison: nullable JSON holding the RetestComparison snapshot, frozen at
 *    generation like phylum_data / plan copy — not recomputed at render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('retest_of_report_id')
                ->nullable()
                ->after('test_id')
                ->constrained('reports')
                ->nullOnDelete();
            $table->json('retest_comparison')->nullable()->after('retest_of_report_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retest_of_report_id');
            $table->dropColumn('retest_comparison');
        });
    }
};
