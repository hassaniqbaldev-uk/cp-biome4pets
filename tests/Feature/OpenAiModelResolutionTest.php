<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\OpenAiService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The OpenAI model is a SINGLE source of truth: Setting::OPENAI_MODEL, resolved
 * through OpenAiService::resolveModel() and read by BOTH generation calls. It must
 * never break or change today's behaviour: empty / invalid / missing always falls
 * back to the config default (gpt-4o). The old per-call PLAN_GENERATION_MODEL is
 * retired and any live value is carried over by the consolidation migration.
 */
class OpenAiModelResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        // The guaranteed default the resolver falls back to (mirrors config/services.php).
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_unset_setting_falls_back_to_config_default_gpt_4o(): void
    {
        // Nothing stored → today's behaviour exactly.
        $this->assertSame('gpt-4o', OpenAiService::resolveModel());
    }

    public function test_a_whitelisted_setting_is_used(): void
    {
        Setting::set(Setting::OPENAI_MODEL, 'gpt-4o-mini');
        $this->assertSame('gpt-4o-mini', OpenAiService::resolveModel());

        Setting::set(Setting::OPENAI_MODEL, 'gpt-4');
        $this->assertSame('gpt-4', OpenAiService::resolveModel());
    }

    public function test_a_plausible_custom_model_id_is_accepted(): void
    {
        // A dated/custom but well-formed id passes the guarded custom check.
        Setting::set(Setting::OPENAI_MODEL, 'gpt-4o-2024-08-06');
        $this->assertSame('gpt-4o-2024-08-06', OpenAiService::resolveModel());
    }

    public function test_empty_or_malformed_setting_falls_back_to_default(): void
    {
        foreach (['', '   ', 'not a model!!', "gpt-4o; drop table", str_repeat('x', 200)] as $bad) {
            Setting::set(Setting::OPENAI_MODEL, $bad);
            $this->assertSame('gpt-4o', OpenAiService::resolveModel(), "bad value [$bad] should fall back");
        }
    }

    public function test_both_generation_calls_read_resolve_model(): void
    {
        // Guard against regression: both API methods must source the model from the
        // single resolver, not a per-call config/setting read.
        $src = file_get_contents(app_path('Services/OpenAiService.php'));

        // The interpretation call and the plan-copy call each assign the model from
        // the single resolver (exactly two call sites; modelOptions() also calls it).
        $this->assertSame(2, substr_count($src, '$model = self::resolveModel();'));
        // The dead 'gpt-4' literal and the per-call plan_generation_model read are gone.
        $this->assertStringNotContainsString("env('OPENAI_MODEL', 'gpt-4')", $src);
        $this->assertStringNotContainsString('Setting::get(Setting::PLAN_GENERATION_MODEL)', $src);
    }

    public function test_temperatures_are_unchanged(): void
    {
        // Behaviour lock: interpretation temperature stays 0.7, plan default stays 0.4.
        $src = file_get_contents(app_path('Services/OpenAiService.php'));
        $this->assertStringContainsString("'temperature' => 0.7", $src);
        $this->assertStringContainsString('is_numeric($temperature) ? (float) $temperature : 0.4', $src);
    }

    public function test_model_options_include_whitelist_and_a_stored_custom_value(): void
    {
        $base = OpenAiService::modelOptions();
        foreach (OpenAiService::MODELS as $m) {
            $this->assertArrayHasKey($m, $base);
        }

        Setting::set(Setting::OPENAI_MODEL, 'gpt-4o-2024-08-06');
        $withCustom = OpenAiService::modelOptions();
        $this->assertArrayHasKey('gpt-4o-2024-08-06', $withCustom);
    }

    // ── Consolidation migration: carry the old value over ────────────────

    public function test_migration_carries_over_a_valid_legacy_plan_generation_model(): void
    {
        // Simulate a live DB that has the old key set and no new key yet.
        DB::table('settings')->where('key', 'openai_model')->delete();
        Setting::set(Setting::PLAN_GENERATION_MODEL, 'gpt-4o-mini');

        $this->runConsolidationUp();

        // Carried over into the new key, and the old key removed.
        $this->assertSame('gpt-4o-mini', Setting::get(Setting::OPENAI_MODEL));
        $this->assertNull(Setting::get(Setting::PLAN_GENERATION_MODEL));
        $this->assertSame('gpt-4o-mini', OpenAiService::resolveModel());
    }

    public function test_migration_leaves_new_key_unset_for_a_blank_legacy_value(): void
    {
        DB::table('settings')->where('key', 'openai_model')->delete();
        Setting::set(Setting::PLAN_GENERATION_MODEL, '');

        $this->runConsolidationUp();

        // Nothing usable to carry → unset → resolver falls back to gpt-4o (as today).
        $this->assertNull(Setting::get(Setting::OPENAI_MODEL));
        $this->assertSame('gpt-4o', OpenAiService::resolveModel());
    }

    public function test_migration_does_not_overwrite_an_already_set_new_key(): void
    {
        Setting::set(Setting::OPENAI_MODEL, 'gpt-4');
        Setting::set(Setting::PLAN_GENERATION_MODEL, 'gpt-4o-mini');

        $this->runConsolidationUp();

        // Existing explicit choice is preserved; old key still retired.
        $this->assertSame('gpt-4', Setting::get(Setting::OPENAI_MODEL));
        $this->assertNull(Setting::get(Setting::PLAN_GENERATION_MODEL));
    }

    /** Re-run only the consolidation migration's up() against the current settings. */
    private function runConsolidationUp(): void
    {
        $migration = require database_path('migrations/2026_06_30_000004_consolidate_openai_model_setting.php');
        $migration->up();
    }
}
