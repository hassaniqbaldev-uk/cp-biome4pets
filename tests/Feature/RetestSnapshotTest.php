<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retest linking + frozen snapshot (data layer). A report linked to an earlier one via
 * retest_of_report_id freezes RetestComparison::build(originalCsv, retestCsv) into
 * retest_comparison at generation, and regenerate refreshes it. A normal report leaves
 * it null. Uses the real Teddy fixtures as the two tests' CSVs.
 */
class RetestSnapshotTest extends TestCase
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
        // No real AI — bind a stub returning non-empty interpretations so regenerate
        // reaches its success path (the deterministic retest snapshot is refreshed
        // up-front regardless, but this keeps the regenerate call ok:true).
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');
    }

    /** Put a fixture CSV on the fake local disk and return its disk-relative path. */
    private function stageCsv(string $fixture, string $relative): string
    {
        Storage::disk('local')->put($relative, file_get_contents(base_path("tests/fixtures/retest/{$fixture}")));

        return $relative;
    }

    /** A Test seeded from a fixture: csv_path on the fake disk + the parsed lab fields. */
    private function makeTest(Pet $pet, Client $client, string $fixture, string $relative): Test
    {
        $parsed = (new CsvParserService)->parse(base_path("tests/fixtures/retest/{$fixture}"));

        return Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'O'.uniqid(),
            'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-01-01',
            'csv_path' => $this->stageCsv($fixture, $relative),
            'csv_data' => ['top_taxa' => $parsed['top_taxa'], 'insight_taxa' => $parsed['insight_taxa']],
            'phylum_data' => $parsed['phylum_totals'],
            'diversity_score' => $parsed['diversity_score'],
            'species_richness' => $parsed['species_richness'],
            'dysbiosis_score' => $parsed['dysbiosis_score'],
            'microbiome_classification' => $parsed['microbiome_classification'],
        ]);
    }

    private function bindFakeAi(): void
    {
        $this->app->bind(OpenAiService::class, fn () => new class extends OpenAiService
        {
            public function generateReportInterpretations(array $p, float $d, array $c = [], array $x = [], ?int $r = null): array
            {
                return [
                    'summary' => 'S.', 'goal' => 'G.', 'bacteroidetes_interpretation' => 'B.',
                    'firmicutes_interpretation' => 'F.', 'fusobacteria_interpretation' => 'Fu.',
                    'proteobacteria_interpretation' => 'P.', 'diversity_interpretation' => 'D.',
                    'vet_summary' => 'V.', 'recommended_actions' => 'A.',
                ];
            }

            public function generateDietGuidance(array $p, float $d, array $c = [], array $x = [], ?int $r = null): array
            {
                return ['include' => 'Inc.', 'avoid' => 'Avo.'];
            }
        });
    }

    public function test_retest_report_freezes_the_comparison_and_regenerate_refreshes_it(): void
    {
        $this->bindFakeAi();
        $client = Client::create(['name' => 'T', 'email' => 't'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy', 'diet' => 'Kibble']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/orig.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/retest.csv');

        // Original report — a normal (non-retest) report: snapshot stays null.
        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $this->assertNull($original->retest_comparison, 'non-retest report has null snapshot');
        $this->assertFalse($original->isRetest());

        // Retest report — created from the S12 test, then linked to the original.
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();
        $this->assertNull($retest->retest_comparison, 'unlinked at creation → still null');

        $retest->update(['retest_of_report_id' => $original->id]);
        $this->assertTrue($retest->fresh()->isRetest());

        // Regenerate: the deterministic snapshot is frozen onto the retest.
        $result = ReportGeneration::regenerateReport($retest->fresh());
        $this->assertTrue($result['ok']);

        $comparison = $retest->fresh()->retest_comparison;
        $this->assertIsArray($comparison);

        // Overview: diversity 3.16 → 2.02, richness 463 → 215, F/B 1.39 → 1.66.
        $this->assertEqualsWithDelta(3.16, $comparison['overview']['original']['diversity'], 0.005);
        $this->assertEqualsWithDelta(2.02, $comparison['overview']['retest']['diversity'], 0.005);
        $this->assertSame(463, $comparison['overview']['original']['species_richness']);
        $this->assertSame(215, $comparison['overview']['retest']['species_richness']);
        $this->assertEqualsWithDelta(1.39, $comparison['overview']['original']['firmicutes_bacteroidetes_ratio'], 0.005);
        $this->assertEqualsWithDelta(1.66, $comparison['overview']['retest']['firmicutes_bacteroidetes_ratio'], 0.005);

        // A couple of taxa deltas.
        $fuso = $this->taxon($comparison, 'genus', 'Fusobacterium');
        $this->assertEqualsWithDelta(22.543, $fuso['original_pct'], 0.02);
        $this->assertEqualsWithDelta(61.224, $fuso['retest_pct'], 0.02);
        $this->assertSame('up', $fuso['direction']);

        $hiranonis = $this->taxon($comparison, 'species', 'Clostridium hiranonis');
        $this->assertEqualsWithDelta(9.886, $hiranonis['original_pct'], 0.02);
        $this->assertEqualsWithDelta(0.469, $hiranonis['retest_pct'], 0.02);
        $this->assertSame('down', $hiranonis['direction']);
    }

    public function test_regenerate_refreshes_an_existing_snapshot(): void
    {
        $this->bindFakeAi();
        $client = Client::create(['name' => 'T', 'email' => 't'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/orig.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/retest.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();
        $retest->update(['retest_of_report_id' => $original->id]);

        ReportGeneration::regenerateReport($retest->fresh());
        $first = $retest->fresh()->retest_comparison;
        $this->assertIsArray($first);

        // Regenerating again re-freezes the same deterministic result (stable).
        ReportGeneration::regenerateReport($retest->fresh());
        $second = $retest->fresh()->retest_comparison;
        $this->assertSame(
            $first['overview']['retest']['diversity'],
            $second['overview']['retest']['diversity'],
        );
        $this->assertEqualsWithDelta(2.02, $second['overview']['retest']['diversity'], 0.005);
    }

    public function test_retest_key_changes_is_seeded_with_the_default_and_edits_survive(): void
    {
        $this->bindFakeAi();
        $client = Client::create(['name' => 'T', 'email' => 't'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/orig.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/retest.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();
        $this->assertNull($retest->retest_key_changes, 'unlinked → still null');

        $retest->update(['retest_of_report_id' => $original->id]);
        ReportGeneration::regenerateReport($retest->fresh());

        $kc = $retest->fresh()->retest_key_changes;
        $this->assertIsArray($kc, 'seeded with the default on generation');
        $incNames = array_column($kc['increases'], 'name');
        $decNames = array_column($kc['decreases'], 'name');
        foreach (['Fusobacterium', 'Streptococcus', 'Sutterella'] as $n) {
            $this->assertContains($n, $incNames, "increase {$n} missing");
        }
        foreach (['Bacteroides', 'Blautia', 'Megamonas', 'Collinsella'] as $n) {
            $this->assertContains($n, $decNames, "decrease {$n} missing");
        }

        // A later team edit must NOT be overwritten by a subsequent regenerate (seeded
        // only when currently null).
        $retest->update(['retest_key_changes' => [
            'increases' => [['name' => 'Hand Edited', 'original_pct' => 1.5, 'retest_pct' => 2.5, 'label' => 'Increased']],
            'decreases' => [],
        ]]);
        ReportGeneration::regenerateReport($retest->fresh());

        $after = $retest->fresh()->retest_key_changes;
        $this->assertSame('Hand Edited', $after['increases'][0]['name'], 'team edit preserved on regenerate');
        $this->assertCount(1, $after['increases']);
        $this->assertSame([], $after['decreases'], 'default was not re-seeded over the edit');
    }

    public function test_non_retest_report_has_null_key_changes(): void
    {
        $this->bindFakeAi();
        $client = Client::create(['name' => 'T', 'email' => 't'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/orig.csv');

        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();
        $this->assertNull($report->retest_key_changes);
        $this->assertNull($report->keyChanges());
    }

    /** Pull one taxon entry at a level from a frozen comparison. */
    private function taxon(array $comparison, string $rank, string $name): array
    {
        foreach ($comparison['taxa'][$rank] as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }
        $this->fail("taxon {$name} not found at {$rank}");
    }
}
