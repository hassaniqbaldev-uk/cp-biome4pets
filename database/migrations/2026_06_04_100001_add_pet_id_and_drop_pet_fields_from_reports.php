<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Nullable for now so the migration runs on existing rows; the wizard
            // always sets pet_id going forward.
            $table->foreignId('pet_id')
                ->nullable()
                ->after('client_id')
                ->constrained()
                ->nullOnDelete();
        });

        // Drop the pet-specific columns now superseded by the Pet model.
        // pet_name  -> Pet.name
        // pet_type  -> obsolete (all pets are dogs)
        // pet_breed -> Pet.breed
        // pet_age   -> Pet.date_of_birth
        // pet_diet  -> Pet.diet
        // pet_health_notes -> Pet.health_notes
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'pet_name',
                'pet_type',
                'pet_breed',
                'pet_age',
                'pet_diet',
                'pet_health_notes',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pet_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->string('pet_name')->after('sample_id');
            $table->string('pet_type')->default('dog')->after('pet_name');
            $table->string('pet_breed')->nullable();
            $table->string('pet_age')->nullable();
            $table->string('pet_diet')->nullable();
            $table->text('pet_health_notes')->nullable();
        });
    }
};
