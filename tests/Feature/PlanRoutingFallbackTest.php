<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\ProductRule;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Test;
use App\Support\ReportGeneration;
use App\Support\ReportQualityValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan-routing fix: an unwell pet that fires NO product trigger (sample 4366) used to
 * get NO plan because of a hardcoded gate. It now routes to the fallback plan
 * (Maintain & Protect) when the configurable toggle is ON (default), still raising a
 * SOFT auto_assigned_maintenance "confirm" flag. Toggle OFF keeps the old behaviour
 * (no plan + unwell_no_plan). Every decision also records a WHY (recommendation_reason).
 */
class PlanRoutingFallbackTest extends TestCase
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
        config(['services.openai.api_key' => '']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $this->seedPlansAndRules();
    }

    /** The real four product rules + a fallback plan and a condition plan. */
    private function seedPlansAndRules(): void
    {
        ProductRule::create(['trigger_name' => 'AMR', 'metric' => 'Bacteroidetes', 'operator' => 'outside', 'value' => 10, 'value2' => 30, 'is_active' => true]);
        ProductRule::create(['trigger_name' => 'Antimicrobic', 'metric' => 'Bacteroidetes', 'operator' => 'gt', 'value' => 30, 'is_active' => true]);
        ProductRule::create(['trigger_name' => 'FMT', 'metric' => 'diversity_score', 'operator' => 'lt', 'value' => 1.6, 'is_active' => true]);
        ProductRule::create(['trigger_name' => 'Prebiotic', 'metric' => 'Firmicutes', 'operator' => 'lt', 'value' => 18, 'is_active' => true]);

        // Condition plan: AMR + Antimicrobic → Reset & Recover (priority 2).
        $reset = Plan::create(['key' => 'reset-recover', 'name' => 'Reset & Recover', 'enabled' => true, 'is_fallback' => false, 'match_priority' => 2]);
        $reset->triggerConditions()->create(['position' => 0, 'required_triggers' => ['AMR', 'Antimicrobic']]);

        // Fallback plan (priority 1000).
        Plan::create(['key' => 'maintain-protect', 'name' => 'Maintain & Protect', 'enabled' => true, 'is_fallback' => true, 'match_priority' => 1000]);
    }

    private function fallbackId(): int
    {
        return Plan::where('key', 'maintain-protect')->value('id');
    }

    // ── recommendPlanWithReason: the decision + WHY ──────────────────────────

    public function test_unwell_no_trigger_routes_to_fallback_when_toggle_on_default(): void
    {
        // Default: toggle unset → treated as ON.
        $result = ReportResource::recommendPlanWithReason([], 'Imbalanced');

        $this->assertSame($this->fallbackId(), $result['plan_id']);
        $this->assertSame('fallback_unwell', $result['reason_code']);
        $this->assertStringContainsString('Maintain & Protect', $result['reason_text']);
        $this->assertStringContainsString('unwell', $result['reason_text']);
    }

    public function test_unwell_no_trigger_returns_null_when_toggle_off(): void
    {
        Setting::set(Setting::UNWELL_NO_TRIGGER_USES_FALLBACK, '0');

        $result = ReportResource::recommendPlanWithReason([], 'Imbalanced & Depleted');

        $this->assertNull($result['plan_id']);
        $this->assertSame('unwell_no_plan', $result['reason_code']);
    }

    public function test_stable_no_trigger_still_gets_the_fallback_as_before(): void
    {
        $result = ReportResource::recommendPlanWithReason([], 'Stable');

        $this->assertSame($this->fallbackId(), $result['plan_id']);
        $this->assertSame('fallback_not_unwell', $result['reason_code']);
    }

    public function test_matched_condition_routes_to_that_plan_with_reason(): void
    {
        $reset = Plan::where('key', 'reset-recover')->value('id');

        $result = ReportResource::recommendPlanWithReason(['AMR', 'Antimicrobic'], 'Imbalanced');

        $this->assertSame($reset, $result['plan_id']);
        $this->assertSame('condition_match', $result['reason_code']);
        $this->assertStringContainsString('Reset & Recover', $result['reason_text']);
        $this->assertStringContainsString('AMR + Antimicrobic', $result['reason_text']);
    }

    public function test_triggers_fired_but_no_match_returns_null_with_reason(): void
    {
        // AMR alone satisfies no condition (Reset needs AMR AND Antimicrobic).
        $result = ReportResource::recommendPlanWithReason(['AMR'], 'Imbalanced');

        $this->assertNull($result['plan_id']);
        $this->assertSame('triggers_no_match', $result['reason_code']);
        $this->assertStringContainsString('AMR', $result['reason_text']);
    }

    public function test_recommendPlanId_still_returns_just_the_id(): void
    {
        $this->assertSame($this->fallbackId(), ReportResource::recommendPlanId([], 'Imbalanced'));
        Setting::set(Setting::UNWELL_NO_TRIGGER_USES_FALLBACK, '0');
        $this->assertNull(ReportResource::recommendPlanId([], 'Imbalanced'));
    }

    // ── The soft review flag ─────────────────────────────────────────────────

    public function test_auto_assigned_maintenance_is_a_soft_flag_when_fallback_used(): void
    {
        $verdict = ReportQualityValidator::validate([
            'interpretations' => ['ai_summary' => 'x'],   // non-empty so it isn't empty_output
            'microbiome_classification' => 'Imbalanced',
            'plan_id' => $this->fallbackId(),
            'plan_reason_code' => 'fallback_unwell',
            'triggered' => [],
        ]);

        $codes = array_column($verdict['issues'], 'code');
        $this->assertContains('auto_assigned_maintenance', $codes);
        $this->assertNotContains('unwell_no_plan', $codes);
        // Soft = warning severity, but deterministic so it surfaces for review.
        $flag = collect($verdict['issues'])->firstWhere('code', 'auto_assigned_maintenance');
        $this->assertSame(ReportQualityValidator::SEVERITY_WARNING, $flag['severity']);
        $this->assertSame(ReportQualityValidator::TIER_DETERMINISTIC, $flag['tier']);
        $this->assertTrue($verdict['needs_review']);
    }

    public function test_unwell_no_plan_flag_still_fires_when_no_plan(): void
    {
        $verdict = ReportQualityValidator::validate([
            'interpretations' => ['ai_summary' => 'x'],
            'microbiome_classification' => 'Imbalanced',
            'plan_id' => null,
            'plan_reason_code' => 'unwell_no_plan',
            'triggered' => [],
        ]);

        $codes = array_column($verdict['issues'], 'code');
        $this->assertContains('unwell_no_plan', $codes);
        $this->assertNotContains('auto_assigned_maintenance', $codes);
    }

    public function test_stable_with_plan_raises_neither_plan_flag(): void
    {
        $verdict = ReportQualityValidator::validate([
            'interpretations' => ['ai_summary' => 'x'],
            'microbiome_classification' => 'Stable',
            'plan_id' => $this->fallbackId(),
            'plan_reason_code' => 'fallback_not_unwell',
            'triggered' => [],
        ]);

        $codes = array_column($verdict['issues'], 'code');
        $this->assertNotContains('auto_assigned_maintenance', $codes);
        $this->assertNotContains('unwell_no_plan', $codes);
    }

    // ── End-to-end: the 4366 scenario through report creation ────────────────

    private function makeUnwellNoTriggerReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'diet' => 'Raw']);
        // 4366-like: all phyla in normal ranges → no trigger fires; classified unwell.
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(), 'report_date' => '2026-06-17',
            'phylum_data' => ['Bacteroidetes' => 20, 'Firmicutes' => 20, 'Proteobacteria' => 21, 'Fusobacteria' => 34],
            'diversity_score' => 2.4, 'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Imbalanced',
            'csv_data' => ['phylum_totals' => ['Bacteroidetes' => 20]],
        ]);

        return ReportGeneration::createReportFromTest($test->fresh());
    }

    public function test_end_to_end_unwell_no_trigger_gets_maintenance_plan_and_reason(): void
    {
        $report = $this->makeUnwellNoTriggerReport()->fresh();

        // Routed to the fallback (Maintain & Protect) — the 4366 fix.
        $this->assertSame($this->fallbackId(), $report->plan_id);

        // WHY captured in the dedicated column (admin-only).
        $this->assertSame('fallback_unwell', $report->recommendation_reason['code']);
        $this->assertStringContainsString('Maintain & Protect', $report->recommendation_reason['text']);

        // Soft review flag raised, needs_review set.
        $this->assertTrue((bool) $report->needs_review);
        $codes = array_column($report->review_flags['issues'] ?? [], 'code');
        $this->assertContains('auto_assigned_maintenance', $codes);
        $this->assertNotContains('unwell_no_plan', $codes);
    }

    public function test_reason_is_not_shown_on_the_customer_report(): void
    {
        $report = $this->makeUnwellNoTriggerReport()->fresh();

        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // The admin-only reason text/code must never leak to the customer page.
        $this->assertStringNotContainsString('fallback_unwell', $html);
        $this->assertStringNotContainsString('no specific trigger fired', $html);
    }

    public function test_toggle_off_end_to_end_leaves_no_plan_with_original_flag(): void
    {
        Setting::set(Setting::UNWELL_NO_TRIGGER_USES_FALLBACK, '0');

        $report = $this->makeUnwellNoTriggerReport()->fresh();

        $this->assertNull($report->plan_id);
        $this->assertSame('unwell_no_plan', $report->recommendation_reason['code']);
        $codes = array_column($report->review_flags['issues'] ?? [], 'code');
        $this->assertContains('unwell_no_plan', $codes);
        $this->assertNotContains('auto_assigned_maintenance', $codes);
    }
}
