<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('sample_id');
            $table->string('pet_name');
            $table->string('pet_type')->default('dog');
            $table->date('report_date');
            $table->string('csv_path')->nullable();
            $table->json('csv_data')->nullable();
            $table->json('phylum_data')->nullable();
            $table->float('diversity_score')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('ai_bacteroidetes_interpretation')->nullable();
            $table->text('ai_firmicutes_interpretation')->nullable();
            $table->text('ai_fusobacteria_interpretation')->nullable();
            $table->text('ai_proteobacteria_interpretation')->nullable();
            $table->text('ai_diversity_interpretation')->nullable();
            $table->text('vet_notes')->nullable();
            $table->string('status')->default('draft');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};