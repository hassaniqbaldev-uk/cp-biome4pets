<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive pet-profile flags (default false, so existing pets get false):
 *   - is_sensitive   — known sensitivity / on vet-prescribed medication.
 *   - is_large_breed — auto-ticked from weight (>= 35 kg) but staff-editable.
 * Stored booleans only; no logic wired to them yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->boolean('is_sensitive')->default(false)->after('diet');
            $table->boolean('is_large_breed')->default(false)->after('is_sensitive');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['is_sensitive', 'is_large_breed']);
        });
    }
};
