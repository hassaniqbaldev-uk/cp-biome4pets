<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product subscription discount (whole percent, nullable). Drives the
 * optional-product "£full, or £discounted with the 6-month subscription discount
 * (n% off)" line on the report. Null = the product shows NO discount line (just
 * its real price), so a product without a configured discount can never display
 * a bogus one. Replaces the former hardcoded "£180 … £126 (30% off)" string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->unsignedTinyInteger('subscription_discount_percent')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn('subscription_discount_percent');
        });
    }
};
