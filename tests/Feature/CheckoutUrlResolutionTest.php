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
 * Stage 4: the customer checkout redirect reads the report's FROZEN checkout url
 * (subscription_snapshot['url'] — variant or base) with the live plan url as
 * fallback. Locks Report::checkoutUrl() and the subscribe interstitial across the
 * three cases: variant (frozen ≠ base), base (frozen == base, unchanged), and old
 * (no frozen url → live fallback).
 */
class CheckoutUrlResolutionTest extends TestCase
{
    private const BASE_URL = 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/BASE';

    private const VARIANT_URL = 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/VARIANT';

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
    }

    /**
     * @param  array|false  $snapshot  the subscription_snapshot to store, or false to store none
     *                                 (a pre-Stage-3 report with no snapshot at all).
     */
    private function makeReport(?string $planUrl, array|false $snapshot): Report
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $plan = Plan::create([
            'key' => 'restore-rebalance-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => $planUrl, 'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-C', 'sample_id' => 'ORD-C',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);

        $attrs = [
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan->id, 'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ];
        if ($snapshot !== false) {
            $attrs['subscription_snapshot'] = $snapshot;
        }
        $report = Report::create($attrs);
        ReportStep::create(['report_id' => $report->id, 'title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    // ── Report::checkoutUrl() (the single source of truth) ────────────────────
    public function test_checkout_url_prefers_frozen_snapshot_url(): void
    {
        $report = $this->makeReport(self::BASE_URL, ['available' => true, 'url' => self::VARIANT_URL]);
        $this->assertSame(self::VARIANT_URL, $report->checkoutUrl());
    }

    public function test_checkout_url_falls_back_to_live_plan_when_snapshot_has_no_url(): void
    {
        // Snapshot present but no url key (e.g. null) → fall back to the live plan.
        $report = $this->makeReport(self::BASE_URL, ['available' => true, 'url' => null]);
        $this->assertSame(self::BASE_URL, $report->checkoutUrl());

        // No snapshot at all (pre-Stage-3) → fall back to the live plan.
        $old = $this->makeReport(self::BASE_URL, snapshot: false);
        $this->assertSame(self::BASE_URL, $old->checkoutUrl());
    }

    public function test_checkout_url_is_null_when_nothing_resolves(): void
    {
        $report = $this->makeReport(null, ['available' => true, 'url' => null]);
        $this->assertNull($report->checkoutUrl());
    }

    // ── End-to-end through the subscribe interstitial ─────────────────────────
    public function test_variant_report_redirects_to_the_variant_checkout(): void
    {
        $report = $this->makeReport(self::BASE_URL, ['available' => true, 'url' => self::VARIANT_URL, 'variant' => 'sensitive']);

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee(self::VARIANT_URL, false)      // sent to the variant's frozen link
            ->assertDontSee(self::BASE_URL, false);    // NOT the base plan link
    }

    public function test_base_report_redirects_to_base_unchanged(): void
    {
        // Base report: frozen url == base plan url (Stage 3 froze it that way).
        $report = $this->makeReport(self::BASE_URL, ['available' => true, 'url' => self::BASE_URL, 'variant' => null]);

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee(self::BASE_URL, false);
    }

    public function test_old_report_without_snapshot_url_redirects_via_live_fallback(): void
    {
        $report = $this->makeReport(self::BASE_URL, snapshot: false);

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee(self::BASE_URL, false);        // live plan url via fallback
    }

    public function test_report_page_subscribe_cta_shows_when_a_checkout_url_resolves(): void
    {
        // show.blade gate uses checkoutUrl(): a frozen-url report shows the CTA
        // (which points at the interstitial, not the raw checkout link).
        $report = $this->makeReport(self::BASE_URL, ['available' => true, 'url' => self::VARIANT_URL]);

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('/report/'.$report->public_token.'/subscribe', false)   // CTA → interstitial
            ->assertDontSee(self::VARIANT_URL, false);                          // raw link not exposed on the report page
    }
}
