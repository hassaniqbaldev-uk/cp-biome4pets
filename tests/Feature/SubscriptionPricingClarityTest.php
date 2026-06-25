<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The subscription pricing relationship is shown clearly and consistently: a
 * plan-INCLUDED product shows its individual price AND the whole-plan monthly
 * price ("£35.00 individually · £29.75 / month on the plan (15% off)"), while an
 * OPTIONAL add-on (e.g. the retest kit) keeps its OWN pricing and is NEVER tagged
 * with the £29.75/mo plan line. All values are derived from the frozen snapshot.
 */
class SubscriptionPricingClarityTest extends TestCase
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
    }

    private function makeReport(CatalogProduct $product, string $inclusion, array $includes = []): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-P', 'sample_id' => 'ORD-P',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create([
            'key' => 'p-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/x',
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id, 'pet_snapshot' => ['name' => 'Biscuit'],
            'subscription_snapshot' => [
                'available' => true,
                'price' => '£29.75 / month',
                'full_price' => '£35 / month',
                'saving_label' => '15% off',
                'url' => 'https://loop.test/x',
                'includes' => $includes,
            ],
        ]);
        $step = ReportStep::create([
            'report_id' => $report->id, 'title' => 'Step 1', 'type' => 'product', 'stage_label' => 'Phase 1', 'position' => 0,
        ]);
        $step->products()->create(['catalog_product_id' => $product->id, 'inclusion' => $inclusion, 'position' => 0]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_plan_included_product_shows_individual_and_plan_price(): void
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $report = $this->makeReport($amr, 'included', includes: [['name' => 'PetBiome AMR', 'price' => 35]]);

        $web = view('report.show', ['report' => $report])->render();

        // The combined, derived line on the product card.
        $this->assertStringContainsString('£35.00 individually', $web);
        $this->assertStringContainsString('£29.75 / month on the plan (15% off)', $web);

        // The subscribe-box list clarifies the individual prices roll up into one sub.
        $this->assertStringContainsString('£35.00 individually', $web);
        $this->assertStringContainsString('All included in the', $web);
        $this->assertStringContainsString('£29.75 / month', $web);
        $this->assertStringContainsString('15% off vs buying each separately', $web);
    }

    public function test_optional_addon_is_excluded_from_the_plan_price_line(): void
    {
        // The retest kit: an optional add-on with its OWN 30% discount.
        $kit = CatalogProduct::create([
            'name' => 'PetBiome Gut Microbiome Test Kit', 'price' => 180,
            'subscription_discount_percent' => 30, 'is_active' => true,
        ]);
        $report = $this->makeReport($kit, 'optional');

        $web = view('report.show', ['report' => $report])->render();

        // Keeps its own pricing: discounted £126 prominent, full £180 as the
        // struck-through "normally" was-price, the 30% derived from its own discount.
        $this->assertStringContainsString('£126', $web);
        $this->assertStringContainsString('normally £180', $web);
        $this->assertStringContainsString('with the 6-month subscription discount (30% off)', $web);

        // ...and must NEVER carry the £29.75/mo plan line (no product is plan-included here).
        $this->assertStringNotContainsString('on the plan', $web);
    }
}
