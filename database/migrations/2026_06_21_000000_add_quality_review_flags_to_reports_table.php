<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 5 / Phase 3 — persist the quality verdict from ReportQualityValidator.
 *
 *  - needs_review : the visible flag. Driven ONLY by deterministic issues
 *    (verdict's needs_review); heuristic issues never set it. Indexed because the
 *    dashboard stat, nav badge and list filter all count/filter on it. Advisory
 *    only — it never blocks publishing.
 *  - review_flags : the full recorded issue list (deterministic AND heuristic,
 *    each {code, severity, tier, detail}) plus a detected_at stamp, kept for the
 *    record and for when heuristics are later tuned and promoted.
 *  - reviewed_at / reviewed_by : audit trail for the "Mark as reviewed"
 *    acknowledgement (we keep review_flags rather than wiping it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->index()->after('status');
            $table->json('review_flags')->nullable()->after('needs_review');
            $table->timestamp('reviewed_at')->nullable()->after('review_flags');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
            $table->dropColumn(['needs_review', 'review_flags', 'reviewed_at', 'reviewed_by']);
        });
    }
};
