<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the powder unit "tub" → "pouch" in stored product quantity text.
 *
 * The unit lives in the free-text `quantity` field on plan_step_products (the plan
 * templates / scaffolds) and report_step_products (the per-report copy taken at
 * apply-plan time). Only the powders ever say "tub" — PetBiome AMR, PetBiome
 * Prebiotic and Antimicrobic, all phrased "N (one tub per month)". Other units are
 * untouched: Gut Renew uses "one course per month", and Maintenance / Test Kit use
 * plain quantities — none contain "tub", so the LIKE-guarded REPLACE skips them.
 *
 * The seeder already emits "pouch" for fresh installs; this fixes the rows already
 * in the database (existing plans + already-generated reports).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['plan_step_products', 'report_step_products'] as $table) {
            DB::table($table)
                ->where('quantity', 'like', '%tub%')
                ->update(['quantity' => DB::raw("REPLACE(quantity, 'tub', 'pouch')")]);
        }
    }

    public function down(): void
    {
        foreach (['plan_step_products', 'report_step_products'] as $table) {
            DB::table($table)
                ->where('quantity', 'like', '%pouch%')
                ->update(['quantity' => DB::raw("REPLACE(quantity, 'pouch', 'tub')")]);
        }
    }
};
