<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_step_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->constrained()->cascadeOnDelete();
            $table->string('duration')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->text('how_it_helps')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['report_step_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_step_products');
    }
};
