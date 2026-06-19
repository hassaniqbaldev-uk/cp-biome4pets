<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D: the test's manual status (results_received / report_generated) was
 * redundant — a test exists because results are in, and whether a report has
 * been generated is already known from the reports relation. The state is now
 * derived (Test::hasReport()), so the stored column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            $table->string('status')->default('report_generated');
        });
    }
};
