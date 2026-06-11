<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('product'); // product | prose
            $table->string('step_title');
            $table->string('stage_label')->nullable(); // e.g. "Phase 1 · Months 1–3"
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_steps');
    }
};
