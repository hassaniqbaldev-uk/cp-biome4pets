<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-report "Send via App" (direct SMTP) tracking — mirrors the existing Klaviyo
 * send fields. Populated ONLY by the manual "Send via App" action; nothing
 * auto-sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestamp('app_last_sent_at')->nullable()->after('klaviyo_last_result');
            $table->json('app_last_result')->nullable()->after('app_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['app_last_sent_at', 'app_last_result']);
        });
    }
};
