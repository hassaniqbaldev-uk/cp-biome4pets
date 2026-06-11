<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Frozen copy of the pet's identity + health notes at generation time,
            // so later edits to the live Pet don't change an already-generated
            // report. Mirrors subscription_snapshot. Nullable: existing reports are
            // NOT backfilled and fall back to the live Pet at render time.
            $table->json('pet_snapshot')->nullable()->after('subscription_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('pet_snapshot');
        });
    }
};
