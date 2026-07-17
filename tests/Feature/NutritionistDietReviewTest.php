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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The nutritionist DIET REVIEW recommendation.
 *
 * Client's rule: show it when the pet is KIBBLE fed AND its classification is
 * "Imbalanced" OR "Imbalanced & Depleted" — replacing the generic nutritionist copy.
 * Any other combination (kibble + Stable, non-kibble + Imbalanced, missing diet,
 * missing classification) keeps the existing generic copy.
 *
 * The two classifications share a prefix, so matching goes through
 * ReportContent::isUnwellClassification() (strict in_array on both exact strings) —
 * never a substring test.
 */
class NutritionistDietReviewTest extends TestCase
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
    }

    /** A published report with a plan + steps, so the next-steps section renders. */
    private function makeReport(?string $diet, ?string $classification): Report
    {
        $product = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $plan = Plan::create([
            'key' => 'restore-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/c', 'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $product->id, 'inclusion' => 'included', 'position' => 0]);

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
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan->id, 'status' => 'published',
            // The frozen snapshot is what the report reads for diet.
            'pet_snapshot' => ['name' => 'Biscuit', 'diet' => $diet],
            'subscription_snapshot' => ['available' => true, 'price' => '£29.75 / month', 'url' => 'https://loop.test/c', 'includes' => []],
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report->fresh();
    }

    private function pdfHtml(Report $report): string
    {
        return view('report.pdf', ['report' => $report->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    private const GENERIC = 'Pets on a kibble diet can benefit from tailored guidance';

    // ── The behaviour matrix ─────────────────────────────────────────────────

    /** Only kibble AND (Imbalanced | Imbalanced & Depleted) triggers the new copy. */
    #[DataProvider('triggerMatrix')]
    public function test_trigger_matrix(?string $diet, ?string $classification, bool $expected): void
    {
        $report = $this->makeReport($diet, $classification);

        $this->assertSame(
            $expected,
            $report->recommendsDietReview(),
            sprintf('diet=%s classification=%s', var_export($diet, true), var_export($classification, true)),
        );
    }

    public static function triggerMatrix(): array
    {
        return [
            // ✅ both conditions met
            'kibble + Imbalanced' => ['Kibble', 'Imbalanced', true],
            'kibble + Imbalanced & Depleted' => ['Kibble', 'Imbalanced & Depleted', true],
            // ❌ right diet, healthy classification
            'kibble + Stable' => ['Kibble', 'Stable', false],
            // ❌ right classification, wrong diet
            'raw + Imbalanced' => ['Raw', 'Imbalanced', false],
            'home cooked + Imbalanced & Depleted' => ['Home Cooked', 'Imbalanced & Depleted', false],
            'non-kibble + Stable' => ['Raw', 'Stable', false],
            // ❌ edge cases → safe fallback
            'missing diet + Imbalanced' => [null, 'Imbalanced', false],
            'kibble + missing classification' => ['Kibble', null, false],
            'kibble + unknown classification' => ['Kibble', 'Unknown', false],
            'both missing' => [null, null, false],
        ];
    }

    // ── Rendering: web + PDF behave identically ──────────────────────────────

    /** Kibble + each unwell classification → the client's copy REPLACES the generic. */
    #[DataProvider('unwellClassifications')]
    public function test_kibble_and_unwell_shows_diet_review_on_web_and_pdf(string $classification): void
    {
        $report = $this->makeReport('Kibble', $classification);
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            // The client's exact wording is present…
            $this->assertStringContainsString(e(ReportContent::dietReviewText()), $html, "{$where}: diet-review copy missing");
            // …the loyalty discount is mentioned…
            $this->assertStringContainsString(e(ReportContent::dietReviewLoyaltyNote()), $html, "{$where}: loyalty note missing");
            // …the product link is a real clickable link…
            $this->assertStringContainsString('href="'.ReportContent::DIET_REVIEW_URL, $html, "{$where}: product link missing");
            $this->assertStringContainsString('microbiome-diet-review-optimisation-60-minutes', $html);
            // …and it REPLACES the generic copy rather than sitting alongside it.
            $this->assertStringNotContainsString(self::GENERIC, $html, "{$where}: generic copy must be replaced");
        }
    }

    public static function unwellClassifications(): array
    {
        return [
            'Imbalanced' => ['Imbalanced'],
            'Imbalanced & Depleted' => ['Imbalanced & Depleted'],
        ];
    }

    /** Every non-triggering combination keeps the existing copy, on both templates. */
    #[DataProvider('nonTriggering')]
    public function test_non_triggering_combinations_keep_the_existing_copy(?string $diet, ?string $classification, bool $kibble): void
    {
        $report = $this->makeReport($diet, $classification);
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            // The diet-review copy and its product link must NOT appear.
            $this->assertStringNotContainsString(e(ReportContent::dietReviewText()), $html, "{$where}: diet-review copy must not show");
            $this->assertStringNotContainsString('microbiome-diet-review-optimisation-60-minutes', $html, "{$where}: product link must not show");

            // Kibble pets still get the existing generic nutritionist nudge;
            // non-kibble pets get no nutritionist block at all (unchanged behaviour).
            if ($kibble) {
                $this->assertStringContainsString(self::GENERIC, $html, "{$where}: generic copy expected");
            } else {
                $this->assertStringNotContainsString(self::GENERIC, $html, "{$where}: no nutritionist block expected");
            }
        }
    }

    public static function nonTriggering(): array
    {
        return [
            'kibble + Stable' => ['Kibble', 'Stable', true],
            'kibble + missing classification' => ['Kibble', null, true],
            'raw + Imbalanced' => ['Raw', 'Imbalanced', false],
            'raw + Stable' => ['Raw', 'Stable', false],
            'missing diet + Imbalanced' => [null, 'Imbalanced', false],
        ];
    }

    /** The shared-prefix guard: "Imbalanced" must not be matched by a substring test,
     *  and "Stable" must never trigger. */
    public function test_classification_matching_is_exact_not_substring(): void
    {
        // Both exact strings trigger…
        $this->assertTrue(ReportContent::isUnwellClassification('Imbalanced'));
        $this->assertTrue(ReportContent::isUnwellClassification('Imbalanced & Depleted'));
        // …and nothing else does, including near-misses and the healthy verdict.
        foreach (['Stable', 'imbalanced', 'Imbalanced & Depleted ', 'Not Imbalanced', 'Unknown', '', null] as $value) {
            $this->assertFalse(ReportContent::isUnwellClassification($value), var_export($value, true).' must not trigger');
        }
    }

    /** The client's wording is used verbatim. */
    public function test_diet_review_copy_is_the_clients_exact_text(): void
    {
        $this->assertSame(
            "We recommend speaking with one of our nutritionists, as your dog's diet may be contributing to their microbiome imbalance. Gut health and nutrition go hand in hand, and by reviewing your dog's microbiome results alongside their current diet, our nutritionists can identify foods and feeding strategies that better support a healthy, balanced microbiome and help optimise long-term gut health.",
            ReportContent::dietReviewText(),
        );
        $this->assertSame(
            'https://biome4pets.com/products/microbiome-diet-review-optimisation-60-minutes',
            ReportContent::DIET_REVIEW_URL,
        );
    }
}
