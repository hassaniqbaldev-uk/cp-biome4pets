<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\ReportStepProduct;
use App\Services\Klaviyo\EventRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "Report Published" Klaviyo event carries a FLAT plan summary + product names,
 * added in the EventRegistry property builder (KlaviyoService, the trigger, the
 * gating and the unique_id are untouched). Everything degrades safely when the report
 * has no plan (plan_id is nullable): has_plan=false, null strings, 0 counts, [].
 */
class KlaviyoPlanPropertiesTest extends TestCase
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

    private function baseReport(?int $planId, array $subscriptionSnapshot = []): Report
    {
        $client = Client::create(['name' => 'Jane Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
            'plan_id' => $planId,
            'subscription_snapshot' => $subscriptionSnapshot,
        ]);
    }

    private function step(Report $report, string $stage, int $position): ReportStep
    {
        return ReportStep::create([
            'report_id' => $report->id, 'title' => $stage, 'type' => 'product',
            'stage_label' => $stage, 'body' => 'x', 'position' => $position,
        ]);
    }

    private function attachProduct(ReportStep $step, string $name, string $inclusion, int $position): void
    {
        $product = CatalogProduct::create(['name' => $name, 'price' => 20, 'is_active' => true]);
        ReportStepProduct::create([
            'report_step_id' => $step->id, 'catalog_product_id' => $product->id,
            'inclusion' => $inclusion, 'position' => $position,
        ]);
    }

    // ── WITH a plan ──────────────────────────────────────────────────────────

    public function test_report_with_a_plan_produces_the_flat_summary(): void
    {
        $plan = Plan::create(['key' => 'restore-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true]);
        $report = $this->baseReport($plan->id, [
            'price' => '£29.75 / month',
            'url' => 'https://loop.test/checkout/CLEAN',
        ]);

        // 2 phases; 3 products across them (mixed inclusion — all are counted/listed).
        $p1 = $this->step($report, 'Phase 1', 0);
        $this->attachProduct($p1, 'PetBiome AMR', 'included', 0);
        $this->attachProduct($p1, 'PetBiome Prebiotic', 'optional', 1);
        $p2 = $this->step($report, 'Phase 2', 1);
        $this->attachProduct($p2, 'PetBiome FMT', 'included', 0);

        $props = $report->fresh()->klaviyoPlanProperties();

        $this->assertTrue($props['has_plan']);
        $this->assertSame('Restore & Rebalance', $props['plan_name']);
        $this->assertSame('£29.75 / month', $props['subscription_price']);
        $this->assertSame('https://loop.test/checkout/CLEAN', $props['subscription_url']);
        $this->assertSame(2, $props['plan_phase_count']);
        $this->assertSame(3, $props['plan_product_count']);
        $this->assertSame(['PetBiome AMR', 'PetBiome Prebiotic', 'PetBiome FMT'], $props['plan_products']);
        // plan_products is a FLAT list of strings — no nested phase/step structure.
        $this->assertContainsOnly('string', $props['plan_products']);
    }

    public function test_plan_with_no_steps_still_reports_the_plan_but_zero_products(): void
    {
        $plan = Plan::create(['key' => 'restore-'.uniqid(), 'name' => 'Maintenance', 'enabled' => true]);
        $report = $this->baseReport($plan->id, ['price' => '£10 / month', 'url' => 'https://loop.test/m']);

        $props = $report->fresh()->klaviyoPlanProperties();

        $this->assertTrue($props['has_plan']);
        $this->assertSame('Maintenance', $props['plan_name']);
        $this->assertSame('£10 / month', $props['subscription_price']);
        $this->assertSame(0, $props['plan_phase_count']);
        $this->assertSame(0, $props['plan_product_count']);
        $this->assertSame([], $props['plan_products']);
    }

    public function test_missing_subscription_snapshot_price_url_are_null_not_errors(): void
    {
        $plan = Plan::create(['key' => 'restore-'.uniqid(), 'name' => 'Bare Plan', 'enabled' => true]);
        // plan_id set but no subscription_snapshot at all.
        $report = $this->baseReport($plan->id, []);

        $props = $report->fresh()->klaviyoPlanProperties();

        $this->assertTrue($props['has_plan']);
        $this->assertNull($props['subscription_price']);
        $this->assertNull($props['subscription_url']);
    }

    // ── WITHOUT a plan — the safe empty shape ────────────────────────────────

    public function test_report_with_no_plan_degrades_safely(): void
    {
        $report = $this->baseReport(planId: null);

        $props = $report->fresh()->klaviyoPlanProperties();

        $this->assertSame([
            'has_plan' => false,
            'plan_name' => null,
            'subscription_price' => null,
            'subscription_url' => null,
            'plan_phase_count' => 0,
            'plan_product_count' => 0,
            'plan_products' => [],
        ], $props);
    }

    /** Even if a planless report somehow carries stray steps, has_plan gates them out. */
    public function test_planless_report_never_leaks_stray_steps(): void
    {
        $report = $this->baseReport(planId: null);
        $orphan = $this->step($report, 'Phase 1', 0);
        $this->attachProduct($orphan, 'Stray', 'included', 0);

        $props = $report->fresh()->klaviyoPlanProperties();

        $this->assertFalse($props['has_plan']);
        $this->assertSame(0, $props['plan_product_count']);
        $this->assertSame([], $props['plan_products']);
    }

    // ── The event builder emits them, null-safe, alongside the existing four ──

    public function test_event_builder_emits_plan_properties_and_keeps_existing_ones(): void
    {
        $def = EventRegistry::get('report_published');

        $props = $def->properties([
            'pet_name' => 'Biscuit', 'report_url' => 'u', 'report_date' => '2026-06-15', 'client_name' => 'Jane',
            'has_plan' => true, 'plan_name' => 'Restore & Rebalance',
            'subscription_price' => '£29.75 / month', 'subscription_url' => 'https://loop.test/c',
            'plan_phase_count' => 2, 'plan_product_count' => 3, 'plan_products' => ['A', 'B', 'C'],
        ]);

        // Existing four unchanged.
        $this->assertSame('Biscuit', $props['pet_name']);
        $this->assertSame('u', $props['report_url']);
        $this->assertSame('2026-06-15', $props['report_date']);
        $this->assertSame('Jane', $props['client_name']);
        // New plan properties present and flat.
        $this->assertTrue($props['has_plan']);
        $this->assertSame('Restore & Rebalance', $props['plan_name']);
        $this->assertSame('£29.75 / month', $props['subscription_price']);
        $this->assertSame('https://loop.test/c', $props['subscription_url']);
        $this->assertSame(2, $props['plan_phase_count']);
        $this->assertSame(3, $props['plan_product_count']);
        $this->assertSame(['A', 'B', 'C'], $props['plan_products']);
    }

    public function test_event_builder_defaults_to_the_no_plan_shape_when_omitted(): void
    {
        $def = EventRegistry::get('report_published');

        // A payload with none of the plan keys (e.g. any legacy caller) must not error
        // and must yield the safe defaults, not missing keys.
        $props = $def->properties(['pet_name' => 'Biscuit']);

        $this->assertFalse($props['has_plan']);
        $this->assertNull($props['plan_name']);
        $this->assertNull($props['subscription_price']);
        $this->assertNull($props['subscription_url']);
        $this->assertSame(0, $props['plan_phase_count']);
        $this->assertSame(0, $props['plan_product_count']);
        $this->assertSame([], $props['plan_products']);
        // Existing keys still present.
        $this->assertArrayHasKey('report_url', $props);
    }
}
