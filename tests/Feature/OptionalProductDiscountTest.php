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
 * #6 — the optional-product "£full, or £discounted with the 6-month subscription
 * discount (n% off)" line is DERIVED from the catalog product's own price and its
 * configured subscription_discount_percent. Previously it was a single hardcoded
 * "£180 … £126 (30% off)" string printed for EVERY optional product, contradicting
 * the real price of anything that wasn't the £180 retest kit.
 */
class OptionalProductDiscountTest extends TestCase
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

    /** A published report whose plan section renders ONE optional product. */
    private function makeReportWithOptionalProduct(CatalogProduct $product): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-D', 'sample_id' => 'ORD-D',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create(['key' => 'p-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true]);

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id, 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);

        $step = ReportStep::create([
            'report_id' => $report->id, 'title' => 'Optional add-on', 'type' => 'product',
            'stage_label' => 'Add-on', 'position' => 0,
        ]);
        $step->products()->create([
            'catalog_product_id' => $product->id, 'inclusion' => 'optional', 'position' => 0,
        ]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_retest_kit_shows_180_126_30pct_from_its_seeded_discount(): void
    {
        // The seeded retest kit: £180 with a 30% subscription discount.
        $kit = CatalogProduct::create([
            'name' => 'PetBiome Gut Microbiome Test Kit', 'price' => 180,
            'subscription_discount_percent' => 30, 'is_active' => true,
        ]);

        $web = view('report.show', ['report' => $this->makeReportWithOptionalProduct($kit)])->render();

        $this->assertStringContainsString(
            '£180, or £126 with the 6-month subscription discount (30% off)',
            $web,
        );
    }

    public function test_discount_derives_from_each_products_own_price_not_a_fixed_string(): void
    {
        // A DIFFERENT optional product: £50 @ 20% → £40. Proves the line is computed,
        // not the old hardcoded 180/126/30%.
        $product = CatalogProduct::create([
            'name' => 'Soothe Plus', 'price' => 50, 'subscription_discount_percent' => 20, 'is_active' => true,
        ]);

        $web = view('report.show', ['report' => $this->makeReportWithOptionalProduct($product)])->render();

        $this->assertStringContainsString(
            '£50, or £40 with the 6-month subscription discount (20% off)',
            $web,
        );
        // The old hardcoded figures must NOT appear for a non-retest product.
        $this->assertStringNotContainsString('£126', $web);
        $this->assertStringNotContainsString('30% off', $web);
    }

    public function test_optional_product_without_discount_shows_no_discount_line(): void
    {
        // £42, no discount configured → plain price only, NO discount line.
        $product = CatalogProduct::create(['name' => 'Plain Add-on', 'price' => 42, 'is_active' => true]);

        $web = view('report.show', ['report' => $this->makeReportWithOptionalProduct($product)])->render();

        $this->assertStringContainsString('£42.00', $web);                              // its real price still shows
        $this->assertStringNotContainsString('subscription discount', $web);           // but no bogus discount line
    }

    public function test_model_helper_computes_discounted_price(): void
    {
        $kit = new CatalogProduct(['price' => 180, 'subscription_discount_percent' => 30]);
        $this->assertSame(126.0, $kit->discountedPrice());
        $this->assertTrue($kit->hasSubscriptionDiscount());

        $plain = new CatalogProduct(['price' => 42, 'subscription_discount_percent' => null]);
        $this->assertNull($plain->discountedPrice());
        $this->assertFalse($plain->hasSubscriptionDiscount());

        $noPrice = new CatalogProduct(['price' => null, 'subscription_discount_percent' => 30]);
        $this->assertNull($noPrice->discountedPrice());
    }
}
