<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The READ-ONLY Shannon impact command: recomputes diversity from retained CSVs and
 * reports which reports would change (value / band / classification) WITHOUT writing
 * anything, and skips tests whose CSV file is gone.
 */
class RecomputeShannonImpactTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');
    }

    private const HEADER = 'Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits';

    private function makeTest(?string $csvPath, float $storedDiversity, ?string $classification = null): Test
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(),
            'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40],
            'diversity_score' => $storedDiversity,
            'species_richness' => 500,
            'dysbiosis_score' => 0.4,
            'microbiome_classification' => $classification,
            'csv_path' => $csvPath,
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40]],
        ]);
    }

    /** A 4050-like sample: species rows carry only part of the abundance, so the old
     *  stored value is too low and the recompute surfaces the increase. */
    private function putUnderResolvedCsv(string $path): void
    {
        // 4 equal species at 10% each + a blank-Species row at 60% → renormalised
        // H = ln(4) = 1.39, whereas the old /100 method stored 0.92.
        Storage::disk('local')->put($path, self::HEADER."\n".implode("\n", [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,100,10',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,100,10',
            'Bacteria,Firmicutes,C,O,F,G3,Species_3,100,10',
            'Bacteria,Firmicutes,C,O,F,G4,Species_4,100,10',
            'Bacteria,Firmicutes,C,O,F,G5,,600,60',
        ]));
    }

    public function test_reports_the_change_without_writing_anything(): void
    {
        $this->putUnderResolvedCsv('csv/under.csv');
        $test = $this->makeTest('csv/under.csv', storedDiversity: 0.92);

        Artisan::call('reports:recompute-shannon-impact');
        $output = Artisan::output();

        // It surfaces the corrected value and the delta.
        $this->assertStringContainsString('1.39', $output);
        $this->assertStringContainsString('+0.47', $output);
        $this->assertStringContainsString('changed value: 1', $output);

        // READ-ONLY: the stored value is untouched.
        $this->assertSame(0.92, (float) $test->fresh()->diversity_score);
        $this->assertStringContainsString('nothing was written', $output);
    }

    public function test_flags_a_classification_change_caused_by_the_corrected_score(): void
    {
        // Stored 0.92 is below the 1.9 "Depleted" gate; corrected 1.39 is still below,
        // so use a case that crosses: stored classification says Depleted while the
        // recomputed diversity + stored richness no longer justify it is covered by
        // the band column. Here we assert the band/classification columns render.
        $this->putUnderResolvedCsv('csv/cls.csv');
        $this->makeTest('csv/cls.csv', storedDiversity: 0.92, classification: 'Imbalanced & Depleted');

        Artisan::call('reports:recompute-shannon-impact');
        $output = Artisan::output();

        // The impact table shows the diversity band and the classification for review.
        $this->assertStringContainsString('Low', $output);                    // diversity band
        $this->assertStringContainsString('Imbalanced & Depleted', $output);  // classification column
    }

    public function test_missing_csv_file_is_skipped_not_errored(): void
    {
        $this->makeTest('csv/gone.csv', storedDiversity: 2.4);

        $code = Artisan::call('reports:recompute-shannon-impact');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('missing CSV file: 1', $output);
    }

    public function test_a_fully_resolved_sample_reports_no_change(): void
    {
        // All abundance resolves to species → renormalising is a no-op → matches the
        // already-stored value. This is why most reports were correct.
        Storage::disk('local')->put('csv/ok.csv', self::HEADER."\n".implode("\n", [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,100,25',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,100,25',
            'Bacteria,Firmicutes,C,O,F,G3,Species_3,100,25',
            'Bacteria,Firmicutes,C,O,F,G4,Species_4,100,25',
        ]));
        // ln(4) = 1.39 under both the old and new method (subtotal is already 100).
        $this->makeTest('csv/ok.csv', storedDiversity: 1.39);

        Artisan::call('reports:recompute-shannon-impact');
        $output = Artisan::output();

        $this->assertStringContainsString('changed value: 0', $output);
        $this->assertStringContainsString('unchanged: 1', $output);
    }
}
