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
 * The subscribe interstitial: its product progression comes from the REPORT's OWN
 * instantiated steps (report_step_products, variant-swapped) — falling back to the
 * live plan only when the report was never instantiated — with the live plan
 * supplying name/pricing. CTA-only, redirecting to the report's RESOLVED checkout
 * URL: the variant-or-base url frozen on the report (subscription_snapshot['url']),
 * with the live plan url as a fallback for pre-Stage-3 reports (Report::checkoutUrl()).
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

    /**
     * @param  ?string  $snapshotUrl  the url FROZEN into subscription_snapshot. Defaults to
     *                                SNAPSHOT_URL; pass null to simulate a pre-Stage-3 report
     *                                that has no frozen url (so checkout falls back to the live plan).
     */
    private function makeReport(?Plan $plan, ?string $snapshotUrl = self::SNAPSHOT_URL): Report
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
            'subscription_snapshot' => ['available' => true, 'price' => '£29.75 / month', 'url' => $snapshotUrl, 'includes' => []],
        ]);
        // A report step so the report's plan section (and subscribe panel) renders.
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    /**
     * Give the report its OWN instantiated product steps (report_step_products) — the
     * variant-aware source the interstitial must read. $first is the "first delivery"
     * product (the swapped one for a variant report); $upcoming is the "what comes
     * next" product. Mirrors a real apply_plan instantiation; a prose step is kept
     * first to prove prose is skipped on the report-step path too.
     */
    private function instantiateSteps(Report $report, CatalogProduct $first, CatalogProduct $upcoming): void
    {
        $p1 = ReportStep::create(['report_id' => $report->id, 'title' => 'Step 1: Microbiome Reset', 'type' => 'product', 'stage_label' => 'Phase 1 · Months 1–3', 'position' => 1]);
        $p1->products()->create(['catalog_product_id' => $first->id, 'duration' => '3 months (12 weeks)', 'quantity' => '3 (one pouch per month)', 'inclusion' => 'included', 'position' => 0]);
        $p2 = ReportStep::create(['report_id' => $report->id, 'title' => 'Step 3: Rebuild', 'type' => 'product', 'stage_label' => 'Phase 2 · Months 4–7', 'position' => 2]);
        $p2->products()->create(['catalog_product_id' => $upcoming->id, 'duration' => '4 months', 'inclusion' => 'included', 'position' => 0]);
    }

    public function test_interstitial_renders_pet_first_product_price_and_cta(): void
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
            ->assertSee(self::SNAPSHOT_URL, false);         // CTA → the report's FROZEN checkout url

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

    public function test_cta_uses_the_frozen_snapshot_url_over_the_live_plan_url(): void
    {
        // Stage 4: the customer is sent to exactly the link FROZEN on their report
        // (e.g. a variant's Loop checkout), not the live plan url which may differ.
        $report = $this->makeReport($this->makePlan(self::LIVE_URL), snapshotUrl: self::SNAPSHOT_URL);

        $res = $this->get('/report/' . $report->public_token . '/subscribe');

        $res->assertSee(self::SNAPSHOT_URL, false);        // the report's frozen checkout URL
        $res->assertDontSee(self::LIVE_URL, false);        // NOT the (different) live plan URL
    }

    public function test_cta_falls_back_to_live_plan_url_when_no_frozen_url(): void
    {
        // Pre-Stage-3 / edge report with no frozen url → fall back to the live plan
        // url, so old reports are unchanged and never break.
        $report = $this->makeReport($this->makePlan(self::LIVE_URL), snapshotUrl: null);

        $res = $this->get('/report/' . $report->public_token . '/subscribe');

        $res->assertSee(self::LIVE_URL, false);            // fallback to the live plan url
    }

    public function test_redirects_when_no_checkout_url_anywhere(): void
    {
        // No frozen url AND no live plan url → nothing to subscribe to → degrade.
        $report = $this->makeReport($this->makePlan(url: null), snapshotUrl: null);

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

    /**
     * THE BUG: a sensitive-pet variant report (plan swapped AMR → AMR Rosemary-Free)
     * showed the standard "PetBiome AMR" on the interstitial. It must now show the
     * report's OWN swapped product (name + image), read from report_step_products —
     * not the live plan's base product.
     */
    public function test_variant_report_shows_the_swapped_product_on_the_interstitial(): void
    {
        $plan = $this->makePlan();                 // live plan: standard AMR (amr.jpg) + Prebiotic
        $rosemaryFree = CatalogProduct::create([
            'name' => 'PetBiome AMR (Rosemary Free)', 'price' => 35, 'is_active' => true,
            'image_path' => 'https://img.test/amr-rf.png',
        ]);
        $prebiotic = CatalogProduct::where('name', 'PetBiome Prebiotic')->firstOrFail();

        $report = $this->makeReport($plan);
        // The report was instantiated with the SWAP applied (first delivery = RF).
        $this->instantiateSteps($report, $rosemaryFree, $prebiotic);

        $res = $this->get('/report/' . $report->public_token . '/subscribe');

        $res->assertOk()
            ->assertSee('PetBiome AMR (Rosemary Free)')        // swapped first-delivery name
            ->assertSee('https://img.test/amr-rf.png', false)  // swapped product image
            // The standard AMR image (the base plan's product) must NOT appear — the
            // interstitial no longer reads the live plan's product. ("PetBiome AMR" as
            // text can't be asserted-absent: it's a substring of the RF name.)
            ->assertDontSee('https://img.test/amr.jpg', false);
    }

    /**
     * PRESERVE: a base (non-variant) report — whose instantiated products equal the
     * base plan's — looks exactly as before: standard AMR, standard image.
     */
    public function test_base_report_is_unchanged_on_the_interstitial(): void
    {
        $plan = $this->makePlan();
        $amr = CatalogProduct::where('name', 'PetBiome AMR')->firstOrFail();
        $prebiotic = CatalogProduct::where('name', 'PetBiome Prebiotic')->firstOrFail();

        $report = $this->makeReport($plan);
        $this->instantiateSteps($report, $amr, $prebiotic); // instantiated == base plan

        $this->get('/report/' . $report->public_token . '/subscribe')
            ->assertOk()
            ->assertSee('PetBiome AMR')                        // standard product
            ->assertSee('https://img.test/amr.jpg', false)     // standard image, as now
            ->assertDontSee('Rosemary Free');
    }

    /**
     * CONSISTENCY: for a variant report, the swapped product appears identically on
     * (a) the report's product card + (b) its subscribe box (report.show) AND
     * (c) the interstitial — all sourced from the report's instantiated data.
     */
    public function test_swapped_product_is_consistent_across_report_card_subscribe_box_and_interstitial(): void
    {
        $plan = $this->makePlan();
        $rosemaryFree = CatalogProduct::create([
            'name' => 'PetBiome AMR (Rosemary Free)', 'price' => 35, 'is_active' => true,
            'image_path' => 'https://img.test/amr-rf.png',
        ]);
        $prebiotic = CatalogProduct::where('name', 'PetBiome Prebiotic')->firstOrFail();

        // The subscribe box on report.show reads the snapshot's includes list, so
        // freeze the swapped product there too (mirrors a real variant instantiation).
        $report = $this->makeReport($plan);
        $report->update(['subscription_snapshot' => array_merge($report->subscription_snapshot, [
            'includes' => [['name' => 'PetBiome AMR (Rosemary Free)', 'price' => 35]],
        ])]);
        $this->instantiateSteps($report, $rosemaryFree, $prebiotic);

        // (a)+(b) the report page (product card + subscribe box).
        $this->get('/report/' . $report->public_token)
            ->assertOk()
            ->assertSee('PetBiome AMR (Rosemary Free)')
            ->assertDontSee('https://img.test/amr.jpg', false);

        // (c) the interstitial — same swapped product.
        $this->get('/report/' . $report->public_token . '/subscribe')
            ->assertOk()
            ->assertSee('PetBiome AMR (Rosemary Free)')
            ->assertDontSee('https://img.test/amr.jpg', false);
    }
}
