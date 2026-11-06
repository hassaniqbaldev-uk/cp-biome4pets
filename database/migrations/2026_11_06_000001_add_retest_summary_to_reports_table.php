<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI-written retest summary (grounded prose only): the overall conclusion plus
 * Aneta's five parts (conclusion, started, improved, attention, meaning, recommend).
 * Generated alongside the interpretations/diet when the report is a retest; null for a
 * non-retest. Sits beside retest_comparison / retest_key_changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('retest_summary')->nullable()->after('retest_key_changes');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('retest_summary');
        });
    }
};
