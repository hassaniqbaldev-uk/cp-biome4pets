<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable copy of the retest "Key Changes" list. Seeded from
 * RetestComparison::keyChangesDefault() at generation (step 7b) and, from step 7c,
 * hand-editable by the team. Null for a non-retest report, exactly like
 * retest_comparison, which it sits beside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('retest_key_changes')->nullable()->after('retest_comparison');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('retest_key_changes');
        });
    }
};
