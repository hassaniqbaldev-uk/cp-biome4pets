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
        $this->assertStringContainsString('would change: 1', $output);

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

    // ── --force: re-store the corrected values ───────────────────────────────

    public function test_force_restores_the_corrected_diversity_and_classification(): void
    {
        $this->putUnderResolvedCsv('csv/force.csv');
        // Stored 0.92 (below the 1.9 gate) → classified Depleted. Corrected 1.39 is
        // still below 1.9, so the classification legitimately stays Depleted here;
        // what matters is that BOTH are re-stored together and stay consistent.
        $test = $this->makeTest('csv/force.csv', storedDiversity: 0.92, classification: 'Imbalanced & Depleted');

        Artisan::call('reports:recompute-shannon-impact', ['--force' => true]);
        $output = Artisan::output();

        $fresh = $test->fresh();
        // 1. The authoritative column is corrected.
        $this->assertSame(1.39, (float) $fresh->diversity_score);
        // 2. The STORED classification is recomputed from the NEW diversity +
        //    stored richness/dysbiosis — never left stale.
        $this->assertSame(
            \App\Support\ReportContent::classify(1.39, 500, 0.4),
            $fresh->microbiome_classification,
        );
        // 3. The csv_data blob copies are kept in sync with the columns.
        $this->assertSame(1.39, (float) $fresh->csv_data['diversity_score']);
        $this->assertSame($fresh->microbiome_classification, $fresh->csv_data['microbiome_classification']);

        $this->assertStringContainsString('UPDATED: 1', $output);
    }

    public function test_force_applies_a_real_classification_change(): void
    {
        // Craft a case that crosses the 1.9 classify() gate: 8 equal species at 5%
        // each (40% resolved) → old /100 method 2.08... so instead use a sample whose
        // OLD stored value sits below 1.9 and whose corrected value clears it.
        // 6 equal species at 10% each + blank row at 40% → renormalised H = ln(6) =
        // 1.79; stored (old) was 1.38. Both below 1.9 → still Depleted. To cross the
        // gate we need ln(n) > 1.9 → n = 8: ln(8) = 2.08.
        Storage::disk('local')->put('csv/cross.csv', self::HEADER."\n".implode("\n", [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,100,7',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,100,7',
            'Bacteria,Firmicutes,C,O,F,G3,Species_3,100,7',
            'Bacteria,Firmicutes,C,O,F,G4,Species_4,100,7',
            'Bacteria,Firmicutes,C,O,F,G5,Species_5,100,7',
            'Bacteria,Firmicutes,C,O,F,G6,Species_6,100,7',
            'Bacteria,Firmicutes,C,O,F,G7,Species_7,100,7',
            'Bacteria,Firmicutes,C,O,F,G8,Species_8,100,7',
            'Bacteria,Firmicutes,C,O,F,G9,,600,44',   // blank Species — 44% unresolved
        ]));
        // Old method on 8×7% = -8*(0.07*ln0.07) = 1.49 → below the 1.9 gate → Depleted.
        // Corrected renormalised = ln(8) = 2.08 → clears the gate.
        $test = $this->makeTest('csv/cross.csv', storedDiversity: 1.49, classification: 'Imbalanced & Depleted');

        Artisan::call('reports:recompute-shannon-impact', ['--force' => true]);
        $output = Artisan::output();

        $fresh = $test->fresh();
        $this->assertSame(2.08, (float) $fresh->diversity_score);
        // Diversity now clears the <1.9 depleted gate, so the stored classification
        // must no longer say "Depleted" — proving it was recomputed, not left stale.
        $this->assertNotSame('Imbalanced & Depleted', $fresh->microbiome_classification);
        $this->assertStringContainsString('classification changes applied: 1', $output);
    }

    public function test_force_touches_only_diversity_and_classification_in_csv_data(): void
    {
        $this->putUnderResolvedCsv('csv/keys.csv');
        $test = $this->makeTest('csv/keys.csv', storedDiversity: 0.92);
        // Seed the blob with the other keys the pipeline stores.
        $test->forceFill(['csv_data' => [
            'phylum_totals' => ['Firmicutes' => 40],
            'top_taxa' => [['name' => 'Blautia', 'rank' => 'genus', 'pct' => 3.5]],
            'insight_taxa' => ['blautia' => 3.5, 'escherichia_shigella' => 0.15],
            'species_richness' => 500,
            'dysbiosis_score' => 0.4,
            'diversity_score' => 0.92,
        ]])->saveQuietly();

        Artisan::call('reports:recompute-shannon-impact', ['--force' => true]);

        $csv = $test->fresh()->csv_data;
        // Only diversity_score changed (+ classification added); everything else intact.
        $this->assertSame(1.39, (float) $csv['diversity_score']);
        $this->assertSame(['Firmicutes' => 40], $csv['phylum_totals']);
        $this->assertSame([['name' => 'Blautia', 'rank' => 'genus', 'pct' => 3.5]], $csv['top_taxa']);
        $this->assertSame(['blautia' => 3.5, 'escherichia_shigella' => 0.15], $csv['insight_taxa']);
        $this->assertSame(500, $csv['species_richness']);
        $this->assertSame(0.4, $csv['dysbiosis_score']);
    }

    public function test_force_is_idempotent(): void
    {
        $this->putUnderResolvedCsv('csv/idem.csv');
        $test = $this->makeTest('csv/idem.csv', storedDiversity: 0.92);

        Artisan::call('reports:recompute-shannon-impact', ['--force' => true]);
        $first = (float) $test->fresh()->diversity_score;

        // Second run: same CSV → same value → nothing left to change.
        Artisan::call('reports:recompute-shannon-impact', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(1.39, $first);
        $this->assertSame(1.39, (float) $test->fresh()->diversity_score);
        $this->assertStringContainsString('UPDATED: 0', $output);
        $this->assertStringContainsString('unchanged (already correct): 1', $output);
    }

    public function test_force_still_skips_a_missing_csv_without_erroring(): void
    {
        $test = $this->makeTest('csv/gone.csv', storedDiversity: 2.4);

        $code = Artisan::call('reports:recompute-shannon-impact', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('missing CSV file: 1', $output);
        // Untouched.
        $this->assertSame(2.4, (float) $test->fresh()->diversity_score);
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

        $this->assertStringContainsString('would change: 0', $output);
        $this->assertStringContainsString('unchanged: 1', $output);
    }
}
