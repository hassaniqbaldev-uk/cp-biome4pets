<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Per-report Klaviyo send tracking. Populated ONLY by the manual
            // "Send Report" action — nothing auto-sends.
            $table->timestamp('klaviyo_last_sent_at')->nullable()->after('subscription_snapshot');
            $table->json('klaviyo_last_result')->nullable()->after('klaviyo_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['klaviyo_last_sent_at', 'klaviyo_last_result']);
        });
    }
};
