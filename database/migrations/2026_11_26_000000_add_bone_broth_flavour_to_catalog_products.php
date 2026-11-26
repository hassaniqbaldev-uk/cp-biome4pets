<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bone broth product MARKER: which catalog product is the chicken one and which is
 * the beef one ('chicken' | 'beef', null for every normal product). This is how the
 * report's bone broth block (and the future Clostridia plan) find the two Boil & Broth
 * products in the catalogue — by marker, not by name.
 *
 * SEPARATE from reports.bone_broth_flavour (the AI's per-dog flavour PICK): that stays
 * on the report and is untouched. Single additive, nullable column; every existing
 * product is unchanged (defaults null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('bone_broth_flavour')->nullable()->after('bb_excluded');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn('bone_broth_flavour');
        });
    }
};
