<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make bulk runs operation-aware so the same infrastructure (run table, page,
 * chunk processor, dashboard card, resume) can drive Send/Re-send next, not just
 * Regenerate. PURELY ADDITIVE — the table is NOT renamed and no existing row is
 * touched: historical/in-flight runs default to operation='regenerate' and behave
 * exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_regenerate_runs', function (Blueprint $table) {
            // regenerate | send | resend — back-compat default for existing rows.
            $table->string('operation')->default('regenerate')->after('started_by')->index();
            // klaviyo | app — null for regenerate (no channel).
            $table->string('channel')->nullable()->after('operation');
            // Reports skipped (e.g. unpublished / no-email on send). Stays 0 for
            // regenerate. regenerated_count/needs_review_count are unchanged.
            $table->unsignedInteger('skipped_count')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_regenerate_runs', function (Blueprint $table) {
            $table->dropIndex(['operation']);
            $table->dropColumn(['operation', 'channel', 'skipped_count']);
        });
    }
};
