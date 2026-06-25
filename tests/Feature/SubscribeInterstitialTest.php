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
 * The subscribe interstitial: server-rendered from the LIVE plan, CTA-only, and
 * redirecting to the live plan's checkout URL (not the report's frozen snapshot).
 */
class SubscribeInterstitialTest extends TestCase
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

    private const LIVE_URL = 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/LIVEXYZ';

    private const SNAPSHOT_URL = 'https://biome4pets.com/OLD-PLACEHOLDER';

    /** A live plan whose FIRST product step is preceded by a prose step (to prove prose is skipped). */
    private function makePlan(?string $url = self::LIVE_URL, bool $enabled = true): Plan
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true, 'image_path' => 'https://img.test/amr.jpg']);
        $prebiotic = CatalogProduct::create(['name' => 'PetBiome Prebiotic', 'price' => 35, 'is_active' => true]); // no image → letter avatar

        $plan = Plan::create([
            'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => $enabled,
            'subscription_available' => true, 'subscription_url' => $url,
            'subscription_price' => '£29.75 / month', 'subscription_full_price' => '£35 / month',
            'subscription_saving_label' => '15% off',
            'subscription_billing_note' => 'Save 15% vs buying separately · billed monthly',
        ]);

        // Prose step FIRST — the controller must skip it for the "first product".
        $plan->steps()->create(['type' => 'prose', 'step_title' => 'Dietary changes', 'stage_label' => 'Alongside Phase 1', 'position' => 0]);
        $s1 = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1: Microbiome Reset', 'stage_label' => 'Phase 1 · Months 1–3', 'position' => 1]);
        $s1->products()->create(['catalog_product_id' => $amr->id, 'duration' => '3 months (12 weeks)', 'quantity' => '3 (one tub per month)', 'inclusion' => 'included', 'position' => 0]);
        $s2 = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 3: Rebuild', 'stage_label' => 'Phase 2 · Months 4–7', 'position' => 2]);
        $s2->products()->create(['catalog_product_id' => $prebiotic->id, 'duration' => '4 months', 'inclusion' => 'included', 'position' => 0]);

        return $plan;
    }

    private function makeReport(?Plan $plan): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o' . uniqid() . '@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-S', 'sample_id' => 'ORD-S',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => []],
        ]);

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan?->id, 'status' => 'published',
            'pet_snapshot' => ['name' => 'Biscuit'],
            'subscription_snapshot' => ['available' => true, 'price' => '£29.75 / month', 'url' => self::SNAPSHOT_URL, 'includes' => []],
        ]);
        // A report step so the report's plan section (and subscribe panel) renders.
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    public function test_interstitial_renders_pet_first_product_price_and_live_cta(): void
    {
        $report = $this->makeReport($this->makePlan());

        $res = $this->get('/report/' . $report->public_token . '/subscribe');

        $res->assertOk()
            ->assertSee('Restore & Rebalance')              // plan badge
            ->assertSee('Biscuit')                          // pet name
            ->assertSee("You'll be redirected automatically", false) // CTA button label
            ->assertSee('PetBiome AMR')                     // first product (the product step)
            ->assertSee('https://img.test/amr.jpg', false)  // its catalog image
            ->assertSee('£29.75 / month')                   // subscription price
            ->assertSee('£35 / month')                      // struck full price
            ->assertSee('15% off')                          // saving badge
            ->assertSee('Phase 2 · Months 4–7')             // what comes next
            ->assertSee('PetBiome Prebiotic')               // upcoming product
            ->assertSee('confirmed at checkout')            // Loop-honesty line
            ->assertSee(self::LIVE_URL, false);             // CTA → live plan URL

        // Prose steps are not part of the product progression.
        $res->assertDontSee('Dietary changes');
    }

    public function test_social_proof_shows_customer_count_not_a_star_rating(): void
    {
        $report = $this->makeReport($this->makePlan());

        $res = $this->get('/report/' . $report->public_token . '/subscribe');

        // Customer-count framing (count falls back to the "1,000+" default).
        $res->assertOk()
            ->assertSee('Join', false)
            ->assertSee('1,000+')
            ->assertSee('Happy Pet Owners');

        // No star-rating / review-score framing.
        $res->assertDontSee('★', false)
            ->assertDontSee('reviews')
            ->assertDontSee('4.9');
    }

    public function test_cta_uses_live_url_not_the_frozen_snapshot(): void
    {
        $report = $this->makeReport($this->makePlan(self::LIVE_URL));

        $res = $this->get('/report/' . $report->public_token . '/subscribe');

        $res->assertSee(self::LIVE_URL, false);            // live plan checkout URL
        $res->assertDontSee(self::SNAPSHOT_URL, false);    // NOT the report's frozen snapshot URL
    }

    public function test_redirects_when_plan_has_no_checkout_url(): void
    {
        $report = $this->makeReport($this->makePlan(url: null));

        $this->get('/report/' . $report->public_token . '/subscribe')
            ->assertRedirect('/report/' . $report->public_token);
    }

    public function test_redirects_when_no_plan(): void
    {
        $report = $this->makeReport(null);

        $this->get('/report/' . $report->public_token . '/subscribe')
            ->assertRedirect('/report/' . $report->public_token);
    }

    public function test_redirects_when_plan_disabled(): void
    {
        $report = $this->makeReport($this->makePlan(enabled: false));

        $this->get('/report/' . $report->public_token . '/subscribe')
            ->assertRedirect('/report/' . $report->public_token);
    }

    public function test_report_subscribe_button_links_to_interstitial(): void
    {
        $report = $this->makeReport($this->makePlan());

        // The report's "Subscribe" button goes to the interstitial, NOT straight
        // to the snapshot's frozen URL.
        $this->get('/report/' . $report->public_token)
            ->assertOk()
            ->assertSee('/report/' . $report->public_token . '/subscribe', false)
            ->assertDontSee('href="' . self::SNAPSHOT_URL . '"', false);
    }
}
