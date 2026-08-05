<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two AI-generated "Personalised Diet Guidance" boxes, snapshotted onto the
 * report like the other ai_* interpretation columns (ai_summary etc.). Frozen at
 * generation time and refreshed on regenerate; nullable so a failed/empty AI call
 * safely leaves them null (the report renders the Step-1 empty box as fallback).
 *   - ai_diet_include : "What your dog can digest" copy (plain text, nl2br on render)
 *   - ai_diet_avoid   : "What your dog should avoid" copy
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->text('ai_diet_include')->nullable()->after('recommended_actions');
            $table->text('ai_diet_avoid')->nullable()->after('ai_diet_include');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['ai_diet_include', 'ai_diet_avoid']);
        });
    }
};
