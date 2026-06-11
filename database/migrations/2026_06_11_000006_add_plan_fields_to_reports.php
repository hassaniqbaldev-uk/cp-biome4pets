<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // The plan applied to this report. nullOnDelete so removing a plan
            // template never deletes published reports.
            $table->foreignId('plan_id')->nullable()->after('goal')->constrained()->nullOnDelete();
            // AI "where to focus first" paragraph for this report.
            $table->text('plan_intro')->nullable()->after('plan_id');
            // Frozen copy of the subscribe-panel data at apply-time, so later
            // edits to the plan template don't change an already-published report.
            $table->json('subscription_snapshot')->nullable()->after('plan_intro');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['plan_intro', 'subscription_snapshot']);
        });
    }
};
