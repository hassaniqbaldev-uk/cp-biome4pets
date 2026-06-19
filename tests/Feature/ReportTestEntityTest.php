<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\CreateReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Support\PetFindings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3a: report generation creates+links a Test that owns the raw lab data,
 * and the Report reads that raw data back transparently via getAttribute proxies.
 */
class ReportTestEntityTest extends TestCase
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
    }

    private function client(): Client
    {
        return Client::create(['name' => 'Owner', 'email' => 'owner' . uniqid() . '@example.com']);
    }

    /** Drive the page's real atomic create path with a realistic data array. */
    private function create(array $data): Report
    {
        $page = new CreateReport();
        $m = new \ReflectionMethod($page, 'handleRecordCreation');
        $m->setAccessible(true);

        return $m->invoke($page, $data);
    }

    private function rawData(Pet $pet, Client $client, string $sampleId): array
    {
        return [
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => $sampleId,
            'report_date' => '2026-06-17',
            'status' => 'draft',
            'csv_path' => 'csv/sample.csv',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 50]],
            'phylum_data' => ['Firmicutes' => 50, 'Bacteroidetes' => 20],
            'diversity_score' => 2.1,
            'species_richness' => 500,
            'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Imbalanced',
        ];
    }

    public function test_creating_a_report_creates_and_links_a_test_with_the_raw_data(): void
    {
        $client = $this->client();
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $report = $this->create($this->rawData($pet, $client, 'ORD-1'));

        $this->assertNotNull($report->test_id, 'report was not linked to a test');

        $test = Test::find($report->test_id);
        $this->assertSame('ORD-1', $test->order_id);   // order_id == sample_id
        $this->assertSame('ORD-1', $test->sample_id);
        $this->assertSame($pet->id, $test->pet_id);
        $this->assertSame($client->id, $test->client_id);
        $this->assertTrue($test->hasReport());   // derived state: a report links it
        $this->assertSame(2.1, (float) $test->diversity_score);
        $this->assertSame(500, (int) $test->species_richness);
        $this->assertSame(['Firmicutes' => 50, 'Bacteroidetes' => 20], $test->phylum_data);
        $this->assertSame('Imbalanced', $test->microbiome_classification);
    }

    public function test_second_report_for_same_pet_and_order_reuses_the_test(): void
    {
        $client = $this->client();
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $r1 = $this->create($this->rawData($pet, $client, 'ORD-7'));
        $r2 = $this->create($this->rawData($pet, $client, 'ORD-7'));

        $this->assertSame(1, Test::count(), 'a duplicate test was created');
        $this->assertSame($r1->test_id, $r2->test_id, 'reports did not share the test');
    }

    public function test_report_proxies_read_raw_data_from_the_test_when_own_columns_are_null(): void
    {
        $client = $this->client();
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-9', 'sample_id' => 'ORD-9',
            'report_date' => '2026-01-01', 'csv_path' => 'csv/z.csv',
            'csv_data' => ['k' => 'v'], 'phylum_data' => ['Firmicutes' => 33],
            'diversity_score' => 3.3, 'species_richness' => 700,
            'dysbiosis_score' => 0.3, 'microbiome_classification' => 'Stable',
        ]);

        // The report has no raw lab columns of its own (dropped in Phase 3d), so
        // every proxied field — including sample_id/report_date — resolves from
        // the linked test.
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'draft',
        ])->fresh();

        $this->assertSame('ORD-9', $report->sample_id);
        $this->assertSame('2026-01-01', $report->report_date?->toDateString());

        $this->assertSame(['Firmicutes' => 33], $report->phylum_data);
        $this->assertSame(3.3, (float) $report->diversity_score);
        $this->assertSame(700, (int) $report->species_richness);
        $this->assertSame(0.3, (float) $report->dysbiosis_score);
        $this->assertSame('Stable', $report->microbiome_classification);
        $this->assertSame('csv/z.csv', $report->csv_path);
        $this->assertSame(['k' => 'v'], $report->csv_data);
    }

    public function test_petfindings_reads_generation_inputs_through_the_proxy(): void
    {
        $client = $this->client();
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-5', 'sample_id' => 'ORD-5',
            'phylum_data' => ['Firmicutes' => 40], 'diversity_score' => 3.2,
            'species_richness' => 650, 'dysbiosis_score' => 0.35,
            'microbiome_classification' => 'Stable', 'report_date' => '2026-06-17',
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'sample_id' => 'ORD-5', 'report_date' => '2026-06-17', 'status' => 'draft',
        ])->fresh();

        $findings = PetFindings::fromReport($report);

        $this->assertArrayHasKey('scores', $findings);
        $this->assertSame(3.2, $findings['scores']['diversity_shannon']);
        $this->assertSame(650, $findings['scores']['species_richness']);
        $this->assertSame('Stable', $findings['scores']['classification']);
    }

    public function test_views_render_for_a_report_whose_raw_data_lives_only_on_the_test(): void
    {
        $client = $this->client();
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'breed' => 'Labrador']);

        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-V', 'sample_id' => 'ORD-V', 'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25, 'Fusobacteria' => 15, 'Proteobacteria' => 10],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'sample_id' => 'ORD-V', 'report_date' => '2026-06-17', 'status' => 'published',
            'score_gut_wall' => 'Medium',
        ])->fresh();
        $report->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);

        // Web view renders without error and shows the pet name.
        $html = view('report.show', ['report' => $report])->render();
        $this->assertStringContainsString('Biscuit', $html);

        // PDF view renders without error (raw data resolved from the test).
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('report.pdf', ['report' => $report])
            ->setPaper('a4', 'portrait')->output();
        $this->assertNotEmpty($pdf);
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }
}
