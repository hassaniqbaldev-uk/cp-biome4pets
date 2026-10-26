<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "No commerce" plan display (Biome Balance reports): a per-report toggle that shows
 * the plan (intro, steps, stage labels, products, durations) but hides all prices,
 * purchase links, discount lines, the subscribe section/button and the "Optional
 * add-on" tag. INDEPENDENT of hide_subscribe (which hides the whole plan section):
 * the two are mutually exclusive, enforced in the model + admin form. Default false,
 * so every existing report is byte-for-byte unchanged. Mirrors the hide_subscribe
 * column (2026_06_26_000000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('hide_commerce')->default(false)->after('hide_subscribe');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('hide_commerce');
        });
    }
};
