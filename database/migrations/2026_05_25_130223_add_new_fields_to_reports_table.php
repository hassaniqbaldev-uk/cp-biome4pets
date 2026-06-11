<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('pet_breed')->nullable();
            $table->string('pet_age')->nullable();
            $table->string('pet_diet')->nullable();
            $table->text('pet_health_notes')->nullable();
            $table->integer('species_richness')->nullable();
            $table->float('dysbiosis_score')->nullable();
            $table->string('microbiome_classification')->nullable();
            $table->string('score_gut_wall')->nullable();
            $table->string('score_skin_allergy')->nullable();
            $table->string('score_behaviour_mood')->nullable();
            $table->string('score_gut_barrier')->nullable();
            $table->string('score_gas_digestive')->nullable();
            $table->string('score_stress_resilience')->nullable();
            $table->text('vet_summary')->nullable();
            $table->text('recommended_actions')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'pet_breed',
                'pet_age',
                'pet_diet',
                'pet_health_notes',
                'species_richness',
                'dysbiosis_score',
                'microbiome_classification',
                'score_gut_wall',
                'score_skin_allergy',
                'score_behaviour_mood',
                'score_gut_barrier',
                'score_gas_digestive',
                'score_stress_resilience',
                'vet_summary',
                'recommended_actions',
            ]);
        });
    }
};
