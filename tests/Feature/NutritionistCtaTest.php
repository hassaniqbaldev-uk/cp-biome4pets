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
 * The "speak to a nutritionist" CTA: triggered off the report's FROZEN diet
 * snapshot (Kibble only), rendered in both the web report and the PDF, absent
 * otherwise. Guards the trigger helper and the dual-maintained templates.
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

    /** A published report with a plan + one step, and the given diet frozen on the snapshot. */
    private function makeReport(?string $diet): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o' . uniqid() . '@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'breed' => 'Labrador', 'diet' => $diet]);

        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-K', 'sample_id' => 'ORD-K',
            'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25, 'Fusobacteria' => 15, 'Proteobacteria' => 10],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => []],
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

    public function test_cta_renders_in_both_web_and_pdf_when_kibble(): void
    {
        $report = $this->makeReport('Kibble');

        $web = view('report.show', ['report' => $report])->render();
        $this->assertStringContainsString(self::HEADING, $web);
        $this->assertStringContainsString(self::LINK, $web);
        // Personalised with the (snapshot) pet name.
        $this->assertStringContainsString("Biscuit's individual results", $web);

        $pdfHtml = view('report.pdf', ['report' => $report])->render();
        $this->assertStringContainsString(self::HEADING, $pdfHtml);
        $this->assertStringContainsString(self::LINK, $pdfHtml);

        // DomPDF renders the (DomPDF-safe) box without error.
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
