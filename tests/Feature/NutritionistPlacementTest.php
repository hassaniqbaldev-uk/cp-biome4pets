<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Support\ReportContent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The nutritionist diet-review block was MOVED from the bottom of "Recommended Next
 * Steps" to Phase 1 (the first phase) so it's seen early. It renders once, and never
 * disappears for a qualifying report: a report with no plan/Phase 1 shows it via a
 * standalone fallback. The trigger (kibble + Imbalanced/Depleted) is unchanged.
 */
class NutritionistPlacementTest extends TestCase
{
    private const HEADING = 'We recommend speaking to a nutritionist';

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
     * @param  bool  $withPlan  give the report a plan + a Phase 1 step (so the plan section renders)
     */
    private function makeReport(string $diet, string $classification, bool $withPlan, bool $hideSubscribe = false): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'diet' => $diet]);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(), 'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40], 'diversity_score' => 2.4,
            'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => $classification,
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40]],
        ]);

        $planId = null;
        $snapshot = [];
        if ($withPlan) {
            $product = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
            $plan = Plan::create([
                'key' => 'restore-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true,
                'subscription_available' => true, 'subscription_url' => 'https://loop.test/c', 'subscription_price' => '£29.75 / month',
            ]);
            $s = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
            $s->products()->create(['catalog_product_id' => $product->id, 'inclusion' => 'included', 'position' => 0]);
            $planId = $plan->id;
            $snapshot = ['available' => true, 'price' => '£29.75 / month', 'url' => 'https://loop.test/c', 'includes' => []];
        }

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $planId, 'status' => 'published', 'hide_subscribe' => $hideSubscribe,
            'pet_snapshot' => ['name' => 'Biscuit', 'diet' => $diet],
            'subscription_snapshot' => $snapshot,
            // With a plan, the "Where to focus first" intro renders (its heading anchors the
            // placement assertion — the block sits directly after this intro).
            'plan_intro' => $withPlan ? 'Biscuit\'s gut shows an imbalance. This plan restores balance.' : null,
        ]);

        if ($withPlan) {
            // Two phases so "Phase 1" is genuinely the first of several.
            ReportStep::create(['report_id' => $report->id, 'title' => 'Phase 1 — Restore', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'Start here.', 'position' => 0]);
            ReportStep::create(['report_id' => $report->id, 'title' => 'Phase 2 — Rebalance', 'type' => 'prose', 'stage_label' => 'Phase 2', 'body' => 'Then this.', 'position' => 1]);
        }

        return $report->fresh();
    }

    private function pdfHtml(Report $report): string
    {
        return view('report.pdf', ['report' => $report->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    // ── With a plan: rendered within Phase 1, once, and NOT at the bottom ─────

    public function test_web_block_sits_between_the_intro_and_the_first_step_and_renders_once(): void
    {
        $report = $this->makeReport('Kibble', 'Imbalanced', withPlan: true);
        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // Present exactly once (not between-intro-and-steps AND the fallback).
        $this->assertSame(1, substr_count($html, self::HEADING), 'block must render exactly once');

        $block = strpos($html, self::HEADING);
        // The block sits BETWEEN the "Where to focus first" intro and the first step box:
        // AFTER the intro heading, BEFORE the first step's title/body — outside any step card.
        $intro = strpos($html, 'Where to focus first');
        $this->assertNotFalse($intro);
        $this->assertLessThan($block, $intro, 'block must come AFTER the "Where to focus first" intro');

        // …before the first step's body content ("Start here."), before Phase 2, and
        // above the closing subscribe nudge.
        $phase1Body = strpos($html, 'Start here.');
        $phase2 = strpos($html, 'Then this.');
        $closingNudge = strpos($html, 'Ready to get started?');
        $this->assertNotFalse($phase1Body);
        $this->assertNotFalse($closingNudge);
        $this->assertLessThan($phase1Body, $block, 'block must come BEFORE the first step content');
        $this->assertLessThan($phase2, $block, 'block must be before Phase 2');
        $this->assertLessThan($closingNudge, $block, 'block must be above the closing subscribe nudge (not at the bottom)');

        // Content intact.
        $this->assertStringContainsString(e(ReportContent::dietReviewText()), $html);
        $this->assertStringContainsString('microbiome-diet-review-optimisation-60-minutes', $html);
        $this->assertStringContainsString(e(ReportContent::dietReviewLoyaltyNote()), $html);
    }

    public function test_pdf_block_sits_between_the_plan_intro_and_the_cta_and_renders_once(): void
    {
        $report = $this->makeReport('Kibble', 'Imbalanced', withPlan: true);
        $html = $this->pdfHtml($report);

        $this->assertSame(1, substr_count($html, self::HEADING));
        $block = strpos($html, self::HEADING);
        // Mirrors the web: AFTER the plan intro sentence, BEFORE the "view plan online" CTA.
        $intro = strpos($html, 'personalised protocol');
        $cta = strpos($html, 'View plan online');
        $this->assertNotFalse($intro);
        $this->assertNotFalse($cta);
        $this->assertLessThan($block, $intro, 'PDF block must sit AFTER the plan intro paragraph');
        $this->assertLessThan($cta, $block, 'PDF block must sit before the "view plan online" CTA');
        $this->assertStringContainsString(e(ReportContent::dietReviewText()), $html);
    }

    // ── No plan / no Phase 1: the fallback still shows it ────────────────────

    public function test_web_fallback_shows_the_block_when_there_is_no_plan(): void
    {
        $report = $this->makeReport('Kibble', 'Imbalanced', withPlan: false);
        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // Never disappears: still exactly one block, with its content.
        $this->assertSame(1, substr_count($html, self::HEADING));
        $this->assertStringContainsString(e(ReportContent::dietReviewText()), $html);
        $this->assertStringContainsString('microbiome-diet-review-optimisation-60-minutes', $html);
        // No plan section, so the closing nudge isn't there.
        $this->assertStringNotContainsString('Ready to get started?', $html);
    }

    public function test_pdf_fallback_shows_the_block_when_there_is_no_plan(): void
    {
        $report = $this->makeReport('Kibble', 'Imbalanced', withPlan: false);
        $html = $this->pdfHtml($report);

        $this->assertSame(1, substr_count($html, self::HEADING));
        $this->assertStringContainsString('Nutrition Support', $html);   // fallback section bar
        $this->assertStringContainsString(e(ReportContent::dietReviewText()), $html);
    }

    // ── Trigger + hide_subscribe unchanged ───────────────────────────────────

    public function test_trigger_is_unchanged_no_block_for_kibble_stable_or_non_kibble(): void
    {
        foreach ([['Kibble', 'Stable'], ['Raw', 'Imbalanced']] as [$diet, $class]) {
            foreach ([true, false] as $withPlan) {
                $report = $this->makeReport($diet, $class, withPlan: $withPlan);
                $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
                $pdf = $this->pdfHtml($report);
                $this->assertStringNotContainsString(self::HEADING, $web, "web: {$diet}/{$class} plan={$withPlan}");
                $this->assertStringNotContainsString(self::HEADING, $pdf, "pdf: {$diet}/{$class} plan={$withPlan}");
            }
        }
    }

    public function test_hide_subscribe_still_suppresses_the_block(): void
    {
        // Qualifying, but staff hid the subscribe pitch → block stays suppressed
        // (preserves existing behaviour; documented caveat).
        $report = $this->makeReport('Kibble', 'Imbalanced', withPlan: true, hideSubscribe: true);
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        $this->assertStringNotContainsString(self::HEADING, $web);
        $this->assertStringNotContainsString(self::HEADING, $pdf);
    }
}
