<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidate the OpenAI model into the single `openai_model` setting.
 *
 * Until now the model lived in `plan_generation_model` (plan-copy only) while the
 * report-interpretation call read config/env. Both calls now read one setting
 * (Setting::OPENAI_MODEL) via OpenAiService::resolveModel(). This migration carries
 * an admin's existing choice across so nothing changes for them:
 *
 *   - If `openai_model` is already set, do nothing (idempotent / already chosen).
 *   - Else, if `plan_generation_model` holds a plausibly-valid model id, copy it
 *     into `openai_model` (preserves the admin's current model).
 *   - A blank/garbage old value is NOT copied → `openai_model` stays unset →
 *     resolveModel() falls back to config('services.openai.model') = gpt-4o, exactly
 *     as today.
 *
 * The retired `plan_generation_model` row is then removed (nothing reads it any
 * more). PURELY a settings-row data migration — no schema change, safe on live, and
 * it never changes today's effective model (gpt-4o when unset).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $alreadySet = DB::table('settings')->where('key', 'openai_model')->value('value');

        if (blank($alreadySet)) {
            $legacy = DB::table('settings')->where('key', 'plan_generation_model')->value('value');
            $legacy = is_string($legacy) ? trim($legacy) : '';

            // Same guard as OpenAiService::resolveModel()'s custom-value check: only a
            // plausibly-formed model id is carried over; anything else is left unset so
            // the resolver falls back to the safe default.
            if ($legacy !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,63}$/', $legacy) === 1) {
                DB::table('settings')->updateOrInsert(
                    ['key' => 'openai_model'],
                    ['value' => $legacy],
                );
            }
        }

        // Retire the old key now its value has been carried over (or was unusable).
        DB::table('settings')->where('key', 'plan_generation_model')->delete();
    }

    public function down(): void
    {
        // Best-effort reverse: copy the model back to the old key and clear the new
        // one, so a rollback restores the pre-consolidation shape.
        if (! Schema::hasTable('settings')) {
            return;
        }

        $model = DB::table('settings')->where('key', 'openai_model')->value('value');

        if (filled($model)) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'plan_generation_model'],
                ['value' => $model],
            );
        }

        DB::table('settings')->where('key', 'openai_model')->delete();
    }
};
