<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: make the trigger-set → plan recommendation data-driven.
 *
 * Each plan_trigger_conditions row is ONE AND-set (required_triggers: all must
 * have fired). Multiple rows for a plan are OR-ed (any satisfied set matches).
 * plans.match_priority is the EXPLICIT precedence (lower = checked first) —
 * deliberately separate from plans.position (display order), which is NOT the
 * recommendation order. plans.is_fallback flags the "no triggers fired" plan
 * (modelled as a flag, never as an empty required_triggers set, which would
 * trivially match everything).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_fallback')->default(false)->after('enabled');
            // Lower = checked first. Default high so a brand-new plan is checked
            // LAST (never silently jumps the queue) until an admin sets it.
            $table->integer('match_priority')->default(1000)->after('is_fallback');
        });

        Schema::create('plan_trigger_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // JSON array of trigger-name strings; ALL must be fired (AND).
            $table->json('required_triggers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_trigger_conditions');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['is_fallback', 'match_priority']);
        });
    }
};
