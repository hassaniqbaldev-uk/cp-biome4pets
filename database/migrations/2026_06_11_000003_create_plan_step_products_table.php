<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_step_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_step_id')->constrained()->cascadeOnDelete();
            // Plans reference live catalogue products by id — the catalogue stays
            // the single source of truth for product name and price.
            $table->foreignId('catalog_product_id')->constrained()->cascadeOnDelete();
            $table->string('duration')->nullable();
            // String, not int: scaffolds carry text like "3 (one tub per month)".
            $table->string('quantity')->nullable();
            $table->string('dose')->nullable();
            $table->string('inclusion')->default('included'); // included | optional
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['plan_step_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_step_products');
    }
};
