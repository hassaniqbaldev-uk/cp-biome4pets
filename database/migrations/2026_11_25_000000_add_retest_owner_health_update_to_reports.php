<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retest "Owner health update": a free-text box where the admin records the owner's
 * current health update (symptoms, improvements, changes reported since the first test).
 *
 * CAPTURE-ONLY for now — stored on the report, shown only on a retest in the admin form.
 * It is deliberately NOT fed into any AI prompt (interpretation/summary); wiring it into
 * generation is a separate, later step. Single additive, nullable column: every existing
 * report is byte-for-byte unchanged (defaults null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->text('retest_owner_health_update')->nullable()->after('retest_summary');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('retest_owner_health_update');
        });
    }
};
