<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The cost-estimate layer (Step 3): editable per-model token rates, an estimated
 * spend computed PER EVENT from that event's model's rate, and a "cost of ~100
 * reports" guide from tracked averages (with a documented baseline fallback).
 * Everything is an ESTIMATE derived from tracked tokens × configured rates — never
 * the amount OpenAI bills.
 */
class AiCostEstimateTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function event(string $type, string $model, int $prompt, int $completion, ?int $daysAgo = null): AiUsageEvent
    {
        $e = AiUsageEvent::create([
            'call_type' => $type,
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
        ]);

        if ($daysAgo !== null) {
            $e->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        return $e;
    }

    // ── Editable rates ───────────────────────────────────────────────────

    public function test_resolve_rates_returns_seeded_defaults_when_unset(): void
    {
        $rates = AiUsageEvent::resolveRates();

        $this->assertSame(0.002, $rates['gpt-4o']['input_per_1k']);
        $this->assertSame(0.008, $rates['gpt-4o']['output_per_1k']);
        // All whitelist models are seeded.
        foreach (['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4'] as $m) {
            $this->assertArrayHasKey($m, $rates);
        }
    }

    public function test_stored_rates_override_the_defaults_and_extend_them(): void
    {
        Setting::set(Setting::OPENAI_TOKEN_RATES, json_encode([
            'gpt-4o' => ['input_per_1k' => 0.005, 'output_per_1k' => 0.02],
            'gpt-4o-2024-08-06' => ['input_per_1k' => 0.001, 'output_per_1k' => 0.004],
        ]));

        $rates = AiUsageEvent::resolveRates();

        // Overridden.
        $this->assertSame(0.005, $rates['gpt-4o']['input_per_1k']);
        // Added.
        $this->assertSame(0.004, $rates['gpt-4o-2024-08-06']['output_per_1k']);
        // Untouched default still present.
        $this->assertSame(0.00012, $rates['gpt-4o-mini']['input_per_1k']);
    }

    public function test_malformed_stored_rates_fall_back_to_defaults(): void
    {
        Setting::set(Setting::OPENAI_TOKEN_RATES, 'not-json');
        $this->assertSame(0.002, AiUsageEvent::resolveRates()['gpt-4o']['input_per_1k']);

        // A row with a non-numeric rate is ignored (defaults for that model survive).
        Setting::set(Setting::OPENAI_TOKEN_RATES, json_encode([
            'gpt-4o' => ['input_per_1k' => 'free', 'output_per_1k' => null],
        ]));
        $this->assertSame(0.002, AiUsageEvent::resolveRates()['gpt-4o']['input_per_1k']);
    }

    // ── Estimated cost from tracked usage ────────────────────────────────

    public function test_cost_is_computed_per_event_with_that_events_model_rate(): void
    {
        // Two models, so the per-event/per-model pricing is exercised.
        $this->event('interpretation', 'gpt-4o', 1000, 1000);        // 0.002 + 0.008 = 0.010
        $this->event('plan_copy', 'gpt-4o', 3000, 2000);             // 0.006 + 0.016 = 0.022
        $this->event('plan_copy', 'gpt-4o-mini', 2000, 1000);        // 0.00024 + 0.00048 = 0.00072

        $cost = AiUsageEvent::costSummary();

        $this->assertEqualsWithDelta(0.03272, $cost['all_time'], 1e-9);
        $this->assertSame([], $cost['missing_rate_models']);
    }

    public function test_cost_last_30_days_excludes_older_events(): void
    {
        $this->event('interpretation', 'gpt-4o', 1000, 1000);            // recent: 0.010
        $this->event('interpretation', 'gpt-4o', 1000, 1000, 40);       // 40 days old: 0.010

        $cost = AiUsageEvent::costSummary();

        $this->assertEqualsWithDelta(0.020, $cost['all_time'], 1e-9);
        $this->assertEqualsWithDelta(0.010, $cost['last_30_days'], 1e-9);
    }

    public function test_a_model_with_no_rate_is_flagged_and_estimated_at_the_fallback(): void
    {
        // A custom model absent from the rates map.
        $this->event('interpretation', 'gpt-4o-2024-08-06', 1000, 1000);

        $cost = AiUsageEvent::costSummary();

        // Flagged…
        $this->assertContains('gpt-4o-2024-08-06', $cost['missing_rate_models']);
        // …but still estimated (at the gpt-4o fallback rate) rather than zero/erroring.
        $this->assertEqualsWithDelta(0.010, $cost['all_time'], 1e-9);
    }

    public function test_cost_summary_is_zeroed_and_safe_when_the_table_is_missing(): void
    {
        Schema::drop('ai_usage_events');

        $cost = AiUsageEvent::costSummary();

        $this->assertSame(0.0, $cost['all_time']);
        $this->assertSame(0.0, $cost['last_30_days']);
        $this->assertSame([], $cost['missing_rate_models']);
    }

    // ── "Cost of ~100 reports" guide ─────────────────────────────────────

    public function test_guide_uses_the_documented_baseline_when_no_data(): void
    {
        $g = AiUsageEvent::reportEstimate(100, null, 'gpt-4o');

        $this->assertSame('baseline', $g['source']);
        $this->assertTrue($g['rate_configured']);
        // 3000 + 1500 prompt, 751 + 1253 completion = 6504 tokens/report.
        $this->assertEqualsWithDelta(4500, $g['prompt_per_report'], 0.001);
        $this->assertEqualsWithDelta(2004, $g['completion_per_report'], 0.001);
        $this->assertEqualsWithDelta(6504, $g['tokens_per_report'], 0.001);
        $this->assertEqualsWithDelta(650400, $g['total_tokens'], 0.001);
        // A regeneration is another interpretation call ≈ 3,751 tokens.
        $this->assertEqualsWithDelta(3751, $g['interpretation_tokens'], 0.001);
        // Priced at gpt-4o: 4500/1k*0.002 + 2004/1k*0.008 = 0.025032 → ×100 = 2.5032.
        $this->assertEqualsWithDelta(2.5032, $g['cost'], 1e-6);
    }

    public function test_guide_uses_tracked_averages_when_both_call_types_recorded(): void
    {
        // Two interpretations (avg prompt 3000 / completion 1500) + one plan copy.
        $this->event('interpretation', 'gpt-4o', 2000, 1000);
        $this->event('interpretation', 'gpt-4o', 4000, 2000);
        $this->event('plan_copy', 'gpt-4o', 1000, 500);

        $g = AiUsageEvent::reportEstimate(100, null, 'gpt-4o');

        $this->assertSame('tracked', $g['source']);
        // per report = avg interp (3000/1500) + the plan copy (1000/500).
        $this->assertEqualsWithDelta(4000, $g['prompt_per_report'], 0.001);
        $this->assertEqualsWithDelta(2000, $g['completion_per_report'], 0.001);
        // 4000/1k*0.002 + 2000/1k*0.008 = 0.024 → ×100 = 2.4.
        $this->assertEqualsWithDelta(2.4, $g['cost'], 1e-6);
    }

    public function test_guide_falls_back_to_baseline_if_only_one_call_type_is_present(): void
    {
        // Only interpretations recorded → not enough to average a whole report.
        $this->event('interpretation', 'gpt-4o', 2000, 1000);

        $g = AiUsageEvent::reportEstimate(100, null, 'gpt-4o');

        $this->assertSame('baseline', $g['source']);
    }

    public function test_guide_flags_an_unknown_model_and_prices_it_at_the_fallback(): void
    {
        $g = AiUsageEvent::reportEstimate(100, null, 'gpt-4o-2024-08-06');

        $this->assertFalse($g['rate_configured']);
        // Priced at the gpt-4o fallback → same as the baseline gpt-4o figure.
        $this->assertEqualsWithDelta(2.5032, $g['cost'], 1e-6);
    }

    public function test_guide_prices_at_the_selected_models_rate(): void
    {
        // gpt-4o-mini is far cheaper than gpt-4o, so the same baseline costs less.
        $mini = AiUsageEvent::reportEstimate(100, null, 'gpt-4o-mini');
        $full = AiUsageEvent::reportEstimate(100, null, 'gpt-4o');

        $this->assertLessThan($full['cost'], $mini['cost']);
        // 4500/1k*0.00012 + 2004/1k*0.00048 = 0.00054 + 0.00096192 = 0.00150192 → ×100.
        $this->assertEqualsWithDelta(0.150192, $mini['cost'], 1e-6);
    }

    // ── Currency labelling ───────────────────────────────────────────────

    public function test_currency_symbol_follows_the_configured_currency(): void
    {
        // Default (unset) → GBP.
        $this->assertSame('GBP', Setting::currencyCode());
        $this->assertSame('£', Setting::currencySymbol());

        Setting::set(Setting::CURRENCY, 'USD');
        $this->assertSame('$', Setting::currencySymbol());

        // Unmapped code degrades to the code plus a space, never blank.
        Setting::set(Setting::CURRENCY, 'JPY');
        $this->assertSame('JPY ', Setting::currencySymbol());
    }
}
