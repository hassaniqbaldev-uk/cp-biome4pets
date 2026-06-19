<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display-only "full" (pre-subscription) price string, e.g. "£35 / month", shown
 * struck through next to the discounted subscription_price so the report clearly
 * conveys the saving (old → new). Free-text, like the other subscription_*
 * display strings — no discount is computed from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('subscription_full_price')->nullable()->after('subscription_price');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('subscription_full_price');
        });
    }
};
