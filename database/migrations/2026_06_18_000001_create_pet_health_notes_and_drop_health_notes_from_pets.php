<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health-notes feature, Part 1: replace the single pets.health_notes column with
 * a dated pet_health_notes log (one row per note/weight entry). Existing pets/
 * notes are dummy — no backfill. Part 2 will rewire AI generation + pet_snapshot
 * to read this log (with date-filtering); this migration only changes storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_health_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('note')->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->timestamps();
        });

        // The single free-text column is fully replaced by the log above.
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('health_notes');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->text('health_notes')->nullable();
        });

        Schema::dropIfExists('pet_health_notes');
    }
};
