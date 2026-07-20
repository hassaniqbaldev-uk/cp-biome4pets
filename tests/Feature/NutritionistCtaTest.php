<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The nutritionist-diet predicate + the RETIREMENT of the old generic CTA.
 *
 * recommendsNutritionist() (Kibble-only) is still the diet half of the trigger, so it
 * is guarded here. But the old "kibble → generic nutritionist nudge" no longer renders:
 * the client's rule is now "leave a stable kibble-fed dog be", so the nutritionist block
 * shows ONLY for kibble + Imbalanced / Imbalanced & Depleted (recommendsDietReview()) —
 * covered in full by NutritionistDietReviewTest. These fixtures use a "Stable"
 * classification to assert the generic CTA is gone and nothing renders.
 */
class NutritionistCtaTest extends TestCase
{
    private const HEADING = 'We recommend speaking to a nutritionist';

    private const LINK = 'https://biome4pets.com/nutritionists';

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

    /**
     * A published report with a plan + one step, and the given diet frozen on the
     * snapshot. Classification defaults to "Stable" so the GENERIC nutritionist copy
     * is the one under test (kibble + an unwell classification shows the diet-review
     * copy instead — see NutritionistDietReviewTest).
     */
    private function makeReport(?string $diet, string $classification = 'Stable'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o' . uniqid() . '@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'breed' => 'Labrador', 'diet' => $diet]);

        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-K', 'sample_id' => 'ORD-K',
            'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25, 'Fusobacteria' => 15, 'Proteobacteria' => 10],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => $classification, 'csv_data' => ['phylum_totals' => []],
        ]);

        $plan = Plan::create(['key' => 'plan-' . uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true]);

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'score_gut_wall' => 'Medium', 'plan_id' => $plan->id,
            'pet_snapshot' => ['name' => 'Biscuit', 'breed' => 'Labrador', 'diet' => $diet],
        ]);

        ReportStep::create([
            'report_id' => $report->id, 'title' => 'Step 1', 'type' => 'prose',
            'stage_label' => 'Phase 1', 'body' => 'Gentle guidance.', 'position' => 0,
        ]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_recommends_nutritionist_is_true_only_for_a_kibble_diet(): void
    {
        $this->assertTrue($this->makeReport('Kibble')->recommendsNutritionist());

        foreach (['Raw', 'Mixed', 'Other', null] as $diet) {
            $this->assertFalse(
                $this->makeReport($diet)->recommendsNutritionist(),
                'diet=' . var_export($diet, true) . ' should not trigger the CTA',
            );
        }
    }

    public function test_kibble_stable_shows_no_nutritionist_block(): void
    {
        // The client's change: a stable kibble-fed dog is left be — the old generic
        // CTA (heading + the generic /nutritionists link) must NOT render.
        $report = $this->makeReport('Kibble', 'Stable');

        $web = view('report.show', ['report' => $report])->render();
        $this->assertStringNotContainsString(self::HEADING, $web);
        $this->assertStringNotContainsString(self::LINK, $web);

        $pdfHtml = view('report.pdf', ['report' => $report])->render();
        $this->assertStringNotContainsString(self::HEADING, $pdfHtml);
        $this->assertStringNotContainsString(self::LINK, $pdfHtml);

        // The PDF still renders fine with the block absent.
        $pdf = Pdf::loadView('report.pdf', ['report' => $report])->setPaper('a4', 'portrait')->output();
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }

    public function test_cta_is_absent_in_both_views_when_not_kibble(): void
    {
        $report = $this->makeReport('Raw');

        $web = view('report.show', ['report' => $report])->render();
        $this->assertStringNotContainsString(self::HEADING, $web);
        $this->assertStringNotContainsString(self::LINK, $web);

        $pdfHtml = view('report.pdf', ['report' => $report])->render();
        $this->assertStringNotContainsString(self::HEADING, $pdfHtml);
        $this->assertStringNotContainsString(self::LINK, $pdfHtml);
    }
}
