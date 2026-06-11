<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('products');
    }

    public function down(): void
    {
        Schema::create('products', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('url')->nullable();
            $table->string('rule_trigger')->nullable();
            $table->timestamps();
        });
    }
};
