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
 * The "Retest Progress" section in the report WEB view (resources/views/report/show.blade.php).
 * It renders ENTIRELY from the frozen retest_comparison snapshot and appears ONLY for a
 * linked retest report. A normal (non-retest) report never renders it. Uses the real Teddy
 * fixtures as the two tests' CSVs; the snapshot is frozen via the step-2/3 generation path.
 */
class RetestProgressWebViewTest extends TestCase
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

    public function test_linked_retest_renders_the_progress_section(): void
    {
        [, $original, $retest] = $this->seedPair();

        // Link + freeze the comparison, then publish so the public route serves it.
        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(['retest_comparison' => ReportGeneration::retestComparisonFor($retest->fresh())]);
        $retest->update(['status' => 'published']);
        $this->assertTrue($retest->fresh()->isRetest());

        $html = $this->get(route('report.show', $retest->fresh()->public_token))
            ->assertOk()
            ->getContent();

        // Section is present.
        $this->assertStringContainsString('Retest Progress', $html);

        // Overview values: diversity 3.16 → 2.02, richness 463 → 215, F/B 1.39 → 1.66.
        foreach (['3.16', '2.02', '463', '215', '1.39', '1.66'] as $needle) {
            $this->assertStringContainsString($needle, $html, "overview value {$needle} missing");
        }

        // Main-shift phyla with their original → retest percentages.
        foreach (['Fusobacteria', '22.69', '61.68', 'Firmicutes', '41.45', '19.69', 'Bacteroidetes', '29.84', '11.89'] as $needle) {
            $this->assertStringContainsString($needle, $html, "phylum token {$needle} missing");
        }

        $this->assertStringContainsString('Main community shifts', $html);
    }

    public function test_linked_retest_renders_the_six_specialists_with_statuses(): void
    {
        [, $original, $retest] = $this->seedPair();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(['retest_comparison' => ReportGeneration::retestComparisonFor($retest->fresh())]);
        $retest->update(['status' => 'published']);

        $html = $this->get(route('report.show', $retest->fresh()->public_token))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Specialist recovery', $html);

        // Each specialist with its expected Teddy status.
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

        // Specialist figures rendered to 2dp (original → retest).
        foreach (['0.00', '0.40', '0.80', '7.50', '2.87', '0.36', '0.02', '0.03', '0.08'] as $v) {
            $this->assertStringContainsString($v, $html, "specialist figure {$v} missing");
        }
    }

    public function test_linked_retest_renders_the_key_changes_boxes(): void
    {
        [, $original, $retest] = $this->seedPair();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(\App\Support\ReportGeneration::retestFreezePayload($retest->fresh()));
        $retest->update(['status' => 'published']);

        $html = $this->get(route('report.show', $retest->fresh()->public_token))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('New priorities', $html);
        $this->assertStringContainsString('Improved', $html);

        // Increases + decreases rows with factual labels.
        foreach (['Fusobacterium', 'Streptococcus', 'Sutterella', 'Bacteroides', 'Blautia', 'Megamonas', 'Collinsella'] as $name) {
            $this->assertStringContainsString($name, $html, "key-change {$name} missing");
        }
        $this->assertStringContainsString('Marked increase', $html);
        $this->assertStringContainsString('Marked reduction', $html);
        $this->assertStringContainsString('Resolved', $html);
    }

    public function test_a_stored_key_changes_value_renders_instead_of_the_default(): void
    {
        [, $original, $retest] = $this->seedPair();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(\App\Support\ReportGeneration::retestFreezePayload($retest->fresh()));
        // Hand-set the column (what step 7c will write) — the view must read it.
        $retest->update(['retest_key_changes' => [
            'increases' => [['name' => 'Edited Riser', 'original_pct' => 0.1, 'retest_pct' => 3.0, 'label' => 'Marked increase']],
            'decreases' => [['name' => 'Edited Faller', 'original_pct' => 9.0, 'retest_pct' => 0.0, 'label' => 'Resolved']],
        ]]);
        $retest->update(['status' => 'published']);

        $html = $this->get(route('report.show', $retest->fresh()->public_token))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Edited Riser', $html);
        $this->assertStringContainsString('Edited Faller', $html);
        // The default rows are gone (the column replaced them).
        $this->assertStringNotContainsString('Fusobacterium', $html);
    }

    public function test_normal_report_does_not_render_the_progress_section(): void
    {
        [, $original] = $this->seedPair();

        $original->update(['status' => 'published']);
        $this->assertFalse($original->fresh()->isRetest());
        $this->assertNull($original->fresh()->retest_comparison);

        $html = $this->get(route('report.show', $original->fresh()->public_token))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Retest Progress', $html);
        $this->assertStringNotContainsString('Main community shifts', $html);
        $this->assertStringNotContainsString('Specialist recovery', $html);
        $this->assertStringNotContainsString('New priorities', $html);

        // The rest of the report still renders (unchanged).
        $this->assertStringContainsString('Microbiome Overview', $html);
    }
}
