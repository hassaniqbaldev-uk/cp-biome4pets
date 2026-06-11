<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_rules', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_name');
            $table->string('metric');
            // gt, lt, gte, lte, between, outside — validated in the app layer
            // (kept as a string for sqlite/test portability rather than a DB enum).
            $table->string('operator');
            $table->decimal('value', 12, 4);
            $table->decimal('value2', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('trigger_name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_rules');
    }
};
