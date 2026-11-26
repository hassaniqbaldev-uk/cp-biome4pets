<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Support\RetestComparison;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The per-report "Legacy diversity method" toggle. OFF (default) = the current
 * species-only Shannon (unchanged). ON = the all-rows Shannon that matches Carol's
 * manual reports (higher numbers). On the Teddy fixtures: species-only ≈ 3.16 / 2.02,
 * legacy all-rows ≈ 3.48 / 2.62. For a retest the flag applies to BOTH columns.
 */
class LegacyDiversityTest extends TestCase
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

    private function fixture(string $name): string
    {
        return base_path("tests/fixtures/retest/{$name}");
    }

    /** A Test seeded with the FULL parse blob as csv_data (so diversity_score_legacy rides along, as in production). */
    private function makeTest(Pet $pet, Client $client, string $fixture, string $relative): Test
    {
        Storage::disk('local')->put($relative, file_get_contents($this->fixture($fixture)));
        $parsed = (new CsvParserService)->parse($this->fixture($fixture));

        return Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'O'.uniqid(),
            'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-01-01',
            'csv_path' => $relative,
            'csv_data' => $parsed,
            'phylum_data' => $parsed['phylum_totals'],
            'diversity_score' => $parsed['diversity_score'],
            'species_richness' => $parsed['species_richness'],
            'dysbiosis_score' => $parsed['dysbiosis_score'],
            'microbiome_classification' => $parsed['microbiome_classification'],
        ]);
    }

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

    public function test_parser_returns_both_species_only_and_all_rows_diversity(): void
    {
        $orig = (new CsvParserService)->parse($this->fixture('teddy_original_S9.csv'));
        $re = (new CsvParserService)->parse($this->fixture('teddy_retest_S12.csv'));

        // Species-only (current) numbers are unchanged.
        $this->assertEqualsWithDelta(3.16, $orig['diversity_score'], 0.01);
        $this->assertEqualsWithDelta(2.02, $re['diversity_score'], 0.01);

        // Legacy all-rows numbers match Carol's manual reports.
        $this->assertEqualsWithDelta(3.48, $orig['diversity_score_legacy'], 0.01);
        $this->assertEqualsWithDelta(2.62, $re['diversity_score_legacy'], 0.01);

        // The two methods are genuinely different (legacy is higher).
        $this->assertGreaterThan($orig['diversity_score'], $orig['diversity_score_legacy']);
        $this->assertGreaterThan($re['diversity_score'], $re['diversity_score_legacy']);
    }

    public function test_migration_adds_legacy_diversity_defaulting_false(): void
    {
        $this->assertTrue(Schema::hasColumn('reports', 'legacy_diversity'));

        [, $original] = $this->seedPair();
        // Default OFF.
        $this->assertFalse((bool) $original->fresh()->legacy_diversity);
    }

    public function test_report_with_toggle_off_reads_species_only_unchanged(): void
    {
        [, $original] = $this->seedPair();
        $original = $original->fresh();

        $this->assertFalse((bool) $original->legacy_diversity);
        // The report proxies the test's species-only figure, exactly as before.
        $this->assertEqualsWithDelta(3.16, (float) $original->diversity_score, 0.01);
    }

    public function test_report_with_toggle_on_reads_all_rows_diversity(): void
    {
        [, $original] = $this->seedPair();
        $original->update(['legacy_diversity' => true]);

        // Same report, same test — only the method changed.
        $this->assertEqualsWithDelta(3.48, (float) $original->fresh()->diversity_score, 0.01);
    }

    public function test_toggling_the_flag_switches_the_value_both_ways(): void
    {
        [, $original] = $this->seedPair();

        $original->update(['legacy_diversity' => true]);
        $this->assertEqualsWithDelta(3.48, (float) $original->fresh()->diversity_score, 0.01);

        $original->update(['legacy_diversity' => false]);
        $this->assertEqualsWithDelta(3.16, (float) $original->fresh()->diversity_score, 0.01);
    }

    public function test_legacy_falls_back_to_species_only_when_legacy_value_absent(): void
    {
        [, $original] = $this->seedPair();
        // Simulate an OLD test parsed before this change: csv_data without the legacy key.
        $test = $original->fresh()->test;
        $csv = $test->csv_data;
        unset($csv['diversity_score_legacy']);
        $test->update(['csv_data' => $csv]);

        $original->update(['legacy_diversity' => true]);
        // No legacy figure stored → safely falls back to the species-only value.
        $this->assertEqualsWithDelta(3.16, (float) $original->fresh()->diversity_score, 0.01);
    }

    public function test_retest_comparison_build_uses_the_flag_for_both_columns(): void
    {
        $o = $this->fixture('teddy_original_S9.csv');
        $r = $this->fixture('teddy_retest_S12.csv');

        $default = RetestComparison::build($o, $r);
        $this->assertEqualsWithDelta(3.16, $default['overview']['original']['diversity'], 0.01);
        $this->assertEqualsWithDelta(2.02, $default['overview']['retest']['diversity'], 0.01);

        $legacy = RetestComparison::build($o, $r, true);
        $this->assertEqualsWithDelta(3.48, $legacy['overview']['original']['diversity'], 0.01);
        $this->assertEqualsWithDelta(2.62, $legacy['overview']['retest']['diversity'], 0.01);
    }

    public function test_frozen_retest_comparison_follows_the_report_flag(): void
    {
        [, $original, $retest] = $this->seedPair();
        $retest->update(['retest_of_report_id' => $original->id]);

        // OFF → species-only for both overview columns.
        $retest->update(['legacy_diversity' => false]);
        $retest->update(['retest_comparison' => ReportGeneration::retestComparisonFor($retest->fresh())]);
        $off = $retest->fresh()->retest_comparison;
        $this->assertEqualsWithDelta(3.16, $off['overview']['original']['diversity'], 0.01);
        $this->assertEqualsWithDelta(2.02, $off['overview']['retest']['diversity'], 0.01);

        // ON → all-rows for BOTH the original and retest columns.
        $retest->update(['legacy_diversity' => true]);
        $retest->update(['retest_comparison' => ReportGeneration::retestComparisonFor($retest->fresh())]);
        $on = $retest->fresh()->retest_comparison;
        $this->assertEqualsWithDelta(3.48, $on['overview']['original']['diversity'], 0.01);
        $this->assertEqualsWithDelta(2.62, $on['overview']['retest']['diversity'], 0.01);
    }
}
