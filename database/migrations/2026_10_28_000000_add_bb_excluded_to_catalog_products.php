<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product "not included in the Biome Balance package" flag. On a no-commerce
 * (hide_commerce) report, a flagged product shows an "Available as an add-on" label
 * instead of "Included in plan" — so a product a BB customer must buy separately
 * (e.g. Gut Renew) reads as outside the package. Display only: prices/URLs are kept,
 * and standard reports (hide_commerce false) ignore the flag entirely. Default false,
 * so every existing product + report is unchanged. Mirrors the price / discount
 * columns (2026_06_09, 2026_06_22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->boolean('bb_excluded')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn('bb_excluded');
        });
    }
};
