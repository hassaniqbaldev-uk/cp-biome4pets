<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-report "Legacy diversity method" toggle. When true, the report's diversity is
 * computed with the all-rows Shannon method (matching Carol's manual reports, which give
 * higher numbers); when false (the default) the current species-only method is used,
 * completely unchanged. Opt-in per report — default false means default behaviour is
 * untouched. One additive, nullable boolean. The all-rows figure itself needs no column:
 * it rides inside the test's csv_data JSON (CsvParserService emits diversity_score_legacy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('legacy_diversity')->default(false)->after('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('legacy_diversity');
        });
    }
};
