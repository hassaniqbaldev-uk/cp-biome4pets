<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI usage tracking — one row per successful OpenAI call, recording the tokens it
 * used (already returned in the API response, previously only logged). Powers the
 * usage totals + cost estimate in the OpenAI settings.
 *
 * PURELY ADDITIVE: a new standalone table, no existing table touched. report_id is
 * nullable (a call often happens before the report is saved) and nulls out if the
 * report is later deleted, so a usage row never blocks a report delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->string('call_type');   // 'interpretation' | 'plan_copy'
            $table->string('model');       // the resolved model used for the call
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();

            // Rollups: by recency and by call type.
            $table->index('created_at');
            $table->index('call_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
