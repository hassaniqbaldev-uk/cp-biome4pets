<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual staff-controlled flag to hide the commercial "Recommended Next Steps" /
 * subscribe pitch on a report (e.g. 6-month retests, or customers already on the
 * programme). Additive + default false, so existing reports are unchanged (the
 * subscribe pitch shows as before). The report's clinical findings are NOT
 * affected by this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('hide_subscribe')->default(false)->after('subscription_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('hide_subscribe');
        });
    }
};
