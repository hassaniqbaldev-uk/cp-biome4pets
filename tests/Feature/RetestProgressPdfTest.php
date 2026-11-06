<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The "Retest Progress" section in the report PDF (resources/views/report/pdf.blade.php),
 * matching the web section (show.blade.php). It renders ENTIRELY from the frozen
 * retest_comparison snapshot and appears ONLY for a linked retest report. A normal report
 * never renders it. Asserts both the rendered PDF HTML (content) and the real DomPDF
 * endpoint (still produces a PDF). Uses the real Teddy fixtures as the two tests' CSVs.
 */
class RetestProgressPdfTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');
    }

    private function makeTest(Pet $pet, Client $client, string $fixture, string $relative): Test
    {
        Storage::disk('local')->put($relative, file_get_contents(base_path("tests/fixtures/retest/{$fixture}")));
        $parsed = (new CsvParserService)->parse(base_path("tests/fixtures/retest/{$fixture}"));

        return Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'O'.uniqid(),
            'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-01-01',
            'csv_path' => $relative,
            'csv_data' => ['top_taxa' => $parsed['top_taxa'], 'insight_taxa' => $parsed['insight_taxa']],
            'phylum_data' => $parsed['phylum_totals'],
            'diversity_score' => $parsed['diversity_score'],
            'species_richness' => $parsed['species_richness'],
            'dysbiosis_score' => $parsed['dysbiosis_score'],
            'microbiome_classification' => $parsed['microbiome_classification'],
        ]);
    }

    /** An original (S9) report and a retest (S12) report for the same pet, both published. */
    private function seedPair(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        return [$pet, $original, $retest];
    }

    /** Render the PDF Blade view to a string (content assertions; the endpoint returns binary). */
    private function pdfHtml(Report $report): string
    {
        return view('report.pdf', ['report' => $report->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    public function test_linked_retest_pdf_renders_the_progress_section(): void
    {
        [, $original, $retest] = $this->seedPair();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(['retest_comparison' => ReportGeneration::retestComparisonFor($retest->fresh())]);
        $retest->update(['status' => 'published']);
        $this->assertTrue($retest->fresh()->isRetest());

        $html = $this->pdfHtml($retest->fresh());

        // Section present.
        $this->assertStringContainsString('Retest Progress', $html);

        // Overview figures: diversity 3.16 → 2.02, richness 463 → 215, F/B 1.39 → 1.66.
        foreach (['3.16', '2.02', '463', '215', '1.39', '1.66'] as $needle) {
            $this->assertStringContainsString($needle, $html, "overview value {$needle} missing");
        }

        // Main-shift phyla with their original → retest percentages.
        foreach (['Fusobacteria', '22.69', '61.68', 'Firmicutes', '41.45', '19.69', 'Bacteroidetes', '29.84', '11.89'] as $needle) {
            $this->assertStringContainsString($needle, $html, "phylum token {$needle} missing");
        }

        $this->assertStringContainsString('Main community shifts', $html);

        // Key Changes boxes with default rows + factual labels.
        $this->assertStringContainsString('New priorities', $html);
        $this->assertStringContainsString('Improved', $html);
        foreach (['Fusobacterium', 'Streptococcus', 'Sutterella', 'Bacteroides', 'Blautia', 'Megamonas', 'Collinsella'] as $name) {
            $this->assertStringContainsString($name, $html, "key-change {$name} missing");
        }
        $this->assertStringContainsString('Marked increase', $html);
        $this->assertStringContainsString('Marked reduction', $html);

        // Specialist recovery block: the six genera with their expected Teddy statuses.
        $this->assertStringContainsString('Specialist recovery', $html);
        $expected = [
            'Akkermansia' => 'Not detected',
            'Faecalibacterium' => 'Recovering',
            'Blautia' => 'Declining',
            'Roseburia' => 'Below target',
            'Bifidobacterium' => 'Below target',
            'Lactobacillus' => 'Recovering',
        ];
        foreach ($expected as $name => $status) {
            $this->assertStringContainsString($name, $html, "specialist {$name} missing");
            $this->assertStringContainsString($status, $html, "status {$status} missing");
        }
        foreach (['0.00', '0.40', '0.80', '7.50', '2.87', '0.36', '0.02', '0.03', '0.08'] as $v) {
            $this->assertStringContainsString($v, $html, "specialist figure {$v} missing");
        }

        // TOC lists "Retest Progress" for a retest (it appears twice: TOC + section bar).
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Retest Progress'));
        $this->assertStringContainsString('&mdash;&nbsp; Retest Progress', $html);

        // The real DomPDF endpoint still renders a PDF.
        $res = $this->get('/report/'.$retest->fresh()->public_token.'/pdf')->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            strtolower($res->headers->get('content-type') ?? ''),
        );
    }

    public function test_a_stored_key_changes_value_renders_in_the_pdf(): void
    {
        [, $original, $retest] = $this->seedPair();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(\App\Support\ReportGeneration::retestFreezePayload($retest->fresh()));
        $retest->update(['retest_key_changes' => [
            'increases' => [['name' => 'Edited Riser', 'original_pct' => 0.1, 'retest_pct' => 3.0, 'label' => 'Marked increase']],
            'decreases' => [['name' => 'Edited Faller', 'original_pct' => 9.0, 'retest_pct' => 0.0, 'label' => 'Resolved']],
        ]]);
        $retest->update(['status' => 'published']);

        $html = $this->pdfHtml($retest->fresh());
        $this->assertStringContainsString('Edited Riser', $html);
        $this->assertStringContainsString('Edited Faller', $html);
        $this->assertStringNotContainsString('Fusobacterium', $html);
    }

    public function test_normal_report_pdf_does_not_render_the_section(): void
    {
        [, $original] = $this->seedPair();

        $original->update(['status' => 'published']);
        $this->assertFalse($original->fresh()->isRetest());
        $this->assertNull($original->fresh()->retest_comparison);

        $html = $this->pdfHtml($original->fresh());

        // Absent entirely — section, block AND the TOC entry.
        $this->assertStringNotContainsString('Retest Progress', $html);
        $this->assertStringNotContainsString('Main community shifts', $html);
        $this->assertStringNotContainsString('Specialist recovery', $html);
        $this->assertStringNotContainsString('New priorities', $html);
        // The rest of the PDF still renders.
        $this->assertStringContainsString('Microbiome Overview', $html);

        // The endpoint still produces a PDF.
        $res = $this->get('/report/'.$original->fresh()->public_token.'/pdf')->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            strtolower($res->headers->get('content-type') ?? ''),
        );
    }
}
