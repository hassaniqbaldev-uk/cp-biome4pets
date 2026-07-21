<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\Plan;
use App\Models\Setting;
use App\Support\ReportGeneration;
use Database\Seeders\CatalogProductSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductRuleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan routing for the unwell + no-trigger case, now governed by the configurable
 * Setting::UNWELL_NO_TRIGGER_USES_FALLBACK toggle (default ON):
 *   - ON (default): an unwell pet that fires no trigger is auto-assigned the
 *     fallback (Maintain & Protect) — the sample-4366 fix — with a soft review flag.
 *   - OFF: the original behaviour — no plan (→ manual selection), unwell_no_plan flag.
 * Stable/unknown always get the fallback; trigger-firing cases are unchanged.
 */
class UnwellPlanRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        (new CatalogProductSeeder())->run();
        (new PlanSeeder())->run();
        (new ProductRuleSeeder())->run();
    }

    private function planId(string $key): int
    {
        return (int) Plan::where('key', $key)->value('id');
    }

    public function test_no_triggers_unwell_routes_to_maintenance_by_default(): void
    {
        // Toggle defaults ON: unwell + no trigger → the fallback (Maintain & Protect).
        $this->assertSame($this->planId('maintain-protect'), ReportResource::recommendPlanId([], 'Imbalanced & Depleted'));
        $this->assertSame($this->planId('maintain-protect'), ReportResource::recommendPlanId([], 'Imbalanced'));
    }

    public function test_no_triggers_unwell_returns_null_when_toggle_off(): void
    {
        Setting::set(Setting::UNWELL_NO_TRIGGER_USES_FALLBACK, '0');

        $this->assertNull(ReportResource::recommendPlanId([], 'Imbalanced & Depleted'));
        $this->assertNull(ReportResource::recommendPlanId([], 'Imbalanced'));
    }

    public function test_no_triggers_stable_still_gets_maintenance_fallback(): void
    {
        $this->assertSame($this->planId('maintain-protect'), ReportResource::recommendPlanId([], 'Stable'));
    }

    public function test_no_triggers_unknown_classification_keeps_legacy_fallback(): void
    {
        // Back-compat: null classification (legacy/no data) → fallback as before.
        $this->assertSame($this->planId('maintain-protect'), ReportResource::recommendPlanId([]));
        $this->assertSame($this->planId('maintain-protect'), ReportResource::recommendPlanId([], null));
    }

    public function test_fired_triggers_match_a_plan_regardless_of_classification(): void
    {
        // Triggers fired → normal matching; classification does not gate this path.
        $this->assertSame(
            $this->planId('restore-rebalance'),
            ReportResource::recommendPlanId(['AMR', 'Prebiotic'], 'Imbalanced & Depleted'),
        );
    }

    public function test_product_selection_routes_the_bug_sample_to_maintenance_by_default(): void
    {
        // The real failing sample: Fusobacteria-dominant, depleted by richness,
        // diversity 2.89 (no FMT). No rule fires → NOW routed to the fallback
        // (Maintain & Protect) by default, with the reason captured.
        $selection = ReportGeneration::productSelection(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            2.89,
            'Imbalanced & Depleted',
        );

        $this->assertSame([], $selection['triggered'], 'no trigger should fire for this sample');
        $this->assertSame($this->planId('maintain-protect'), $selection['plan_id'], 'unwell + no trigger now gets the fallback');
        $this->assertSame('fallback_unwell', $selection['reason_code']);
    }

    public function test_product_selection_gates_the_bug_sample_when_toggle_off(): void
    {
        Setting::set(Setting::UNWELL_NO_TRIGGER_USES_FALLBACK, '0');

        $selection = ReportGeneration::productSelection(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            2.89,
            'Imbalanced & Depleted',
        );

        $this->assertSame([], $selection['triggered']);
        $this->assertNull($selection['plan_id'], 'toggle OFF preserves the old no-plan behaviour');
        $this->assertSame('unwell_no_plan', $selection['reason_code']);
    }

    public function test_product_selection_stable_no_trigger_still_maintenance(): void
    {
        $selection = ReportGeneration::productSelection(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            2.89,
            'Stable',
        );

        $this->assertSame([], $selection['triggered']);
        $this->assertSame($this->planId('maintain-protect'), $selection['plan_id']);
    }
}
