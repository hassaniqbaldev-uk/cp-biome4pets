<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-only "why this plan" reason captured at plan-selection time
 * (ReportResource::recommendPlanWithReason). Stored as JSON {code, text}: the
 * machine code for later filtering + the human sentence shown beside the plan
 * selector on the report edit page. Nullable and separate from review_flags so
 * it is present on EVERY report (including clean auto-matches, which carry no
 * review flags) and is never rewritten by the quality-grader recompute. Never
 * shown on the customer-facing report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('recommendation_reason')->nullable()->after('review_flags');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('recommendation_reason');
        });
    }
};
