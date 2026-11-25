<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bone broth flavour pick: the AI-recommended bone broth protein for this dog
 * ('chicken', 'beef' or 'either'), generated as a third key on the existing diet
 * guidance call and admin-overridable before publish.
 *
 * CAPTURE + OVERRIDE only in this step — stored on the report; NOT yet shown on the
 * public report or PDF. Single additive, nullable column: every existing report is
 * unchanged (defaults null; null is treated as "either" at read time by the UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('bone_broth_flavour')->nullable()->after('ai_diet_avoid');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('bone_broth_flavour');
        });
    }
};
