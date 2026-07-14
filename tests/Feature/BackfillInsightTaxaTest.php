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
 * Stage 1 backfill: reports:backfill-insight-taxa reads each test's RETAINED CSV
 * and populates csv_data['insight_taxa'] — additive, idempotent, and guarded so a
 * test whose CSV file is missing is skipped rather than erroring. It must not
 * disturb any other stored data.
 */
class BackfillInsightTaxaTest extends TestCase
{
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');
    }

    private const HEADER = 'Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits';

    private function makeTest(?string $csvPath, ?array $csvData = null): Test
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'ORD-'.uniqid(),
            'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40, 'Bacteroidetes' => 25],
            'csv_path' => $csvPath,
            'csv_data' => $csvData ?? ['phylum_totals' => ['Firmicutes' => 40]],
        ]);
    }

    public function test_backfills_insight_taxa_from_retained_csv_without_disturbing_other_data(): void
    {
        Storage::disk('local')->put('csv/a.csv', self::HEADER."\n".implode("\n", [
            'Bacteria,Firmicutes,Clostridia,Clostridiales,Lachnospiraceae,Blautia,Blautia_producta(AB001),40,0.80',
            'Bacteria,Proteobacteria,Gamma,Enterobacterales,Enterobacteriaceae,Escherichia-Shigella,Escherichia_coli(X1),8,0.15',
        ]));

        $test = $this->makeTest('csv/a.csv', ['phylum_totals' => ['Firmicutes' => 40], 'top_taxa' => [['name' => 'Blautia']]]);

        $this->assertArrayNotHasKey('insight_taxa', $test->csv_data);

        Artisan::call('reports:backfill-insight-taxa');

        $test->refresh();
        $this->assertSame(['blautia' => 0.8, 'escherichia_shigella' => 0.15], $test->csv_data['insight_taxa']);
        // Untouched: other csv_data keys and phylum_data survive.
        $this->assertSame([['name' => 'Blautia']], $test->csv_data['top_taxa']);
        $this->assertSame(['Firmicutes' => 40, 'Bacteroidetes' => 25], $test->phylum_data);
    }

    public function test_missing_csv_file_is_skipped_not_errored(): void
    {
        // csv_path points nowhere (file pruned on a very old record).
        $test = $this->makeTest('csv/gone.csv');

        $code = Artisan::call('reports:backfill-insight-taxa');

        $this->assertSame(0, $code);
        $test->refresh();
        $this->assertArrayNotHasKey('insight_taxa', $test->csv_data);
        $this->assertStringContainsString('missing file 1', Artisan::output());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Storage::disk('local')->put('csv/b.csv', self::HEADER."\nBacteria,Firmicutes,Clostridia,Clostridiales,Lachnospiraceae,Blautia,Blautia_producta(AB001),40,0.80");
        $test = $this->makeTest('csv/b.csv');

        Artisan::call('reports:backfill-insight-taxa', ['--dry-run' => true]);

        $test->refresh();
        $this->assertArrayNotHasKey('insight_taxa', $test->csv_data);
    }

    public function test_is_idempotent_second_run_reports_unchanged(): void
    {
        Storage::disk('local')->put('csv/c.csv', self::HEADER."\nBacteria,Firmicutes,Clostridia,Clostridiales,Lachnospiraceae,Blautia,Blautia_producta(AB001),40,0.80");
        $this->makeTest('csv/c.csv');

        Artisan::call('reports:backfill-insight-taxa');
        Artisan::call('reports:backfill-insight-taxa');

        $this->assertStringContainsString('unchanged 1', Artisan::output());
    }
}
