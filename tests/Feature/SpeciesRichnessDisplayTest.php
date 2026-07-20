<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Support\ReportContent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Species Richness displays WITH its Low/Moderate/Healthy band, colour and legend —
 * the same treatment as Diversity and Dysbiosis. (An earlier change stripped the band
 * to a bare number; the client reversed that — "keep it as it is".) The value also
 * gates classify() at < 400, unchanged.
 */
class SpeciesRichnessDisplayTest extends TestCase
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

    private function makeReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(), 'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4,          // Medium diversity band (keeps its band)
            'species_richness' => 512,         // between 400 and 650
            'dysbiosis_score' => 0.35,         // Healthy dysbiosis band (keeps its band)
            'microbiome_classification' => 'Imbalanced',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40]],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
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

    public function test_richness_shows_its_number_band_and_legend(): void
    {
        $report = $this->makeReport();
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        // richness 512 → the "Moderate" band (400–650); that label is unique to the
        // richness scale, so its presence proves the banded card is back.
        $band = ReportContent::richnessBand(512);
        $this->assertSame('Moderate', $band['label']);

        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            $this->assertStringContainsString('512', $html, "{$where}: richness number missing");
            $this->assertStringContainsString('Species Richness', $html);
            // The band verdict…
            $this->assertStringContainsString($band['label'], $html, "{$where}: richness band label missing");
            // …and the full Low/Moderate/Healthy legend scale. Blade escapes the
            // range strings (the "<"/">" become &lt;/&gt;), so compare the escaped form.
            foreach (ReportContent::richnessLegend() as $b) {
                $this->assertStringContainsString(e($b['range']), $html, "{$where}: richness legend '{$b['range']}' missing");
            }
        }
    }

    public function test_all_three_overview_metrics_show_their_legends(): void
    {
        $report = $this->makeReport();
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        // Diversity, Richness and Dysbiosis all render their band legends consistently.
        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            $this->assertStringContainsString(ReportContent::diversityLegend()[1]['range'], $html, "{$where}: diversity legend missing");
            $this->assertStringContainsString(ReportContent::richnessLegend()[1]['range'], $html, "{$where}: richness legend missing");
            $this->assertStringContainsString(ReportContent::dysbiosisLegend()[1]['range'], $html, "{$where}: dysbiosis legend missing");
        }
    }

    /** The "Understanding your results" explanation for richness still shows (that copy
     *  is separate from the metric card and was not reverted). */
    public function test_richness_explanation_text_still_renders(): void
    {
        $report = $this->makeReport();
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        $richnessExplanation = collect(ReportContent::resultsExplanations())
            ->firstWhere('title', 'Species Richness')['text'];

        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            $this->assertStringContainsString(e($richnessExplanation), $html, "{$where}: richness explanation text missing");
        }
    }

    /** classify() is unchanged: richness < 400 still drives "Imbalanced & Depleted". */
    public function test_richness_still_gates_classification(): void
    {
        // Same diversity + dysbiosis, only richness differs across the 400 cutoff.
        $this->assertSame(
            ReportContent::CLASSIFICATION_DEPLETED,
            ReportContent::classify(2.0, 300, 1.0),       // richness 300 < 400 → depleted
        );
        $this->assertSame(
            ReportContent::CLASSIFICATION_IMBALANCED,
            ReportContent::classify(2.0, 500, 1.0),       // richness 500 ≥ 400 → not depleted
        );
    }
}
