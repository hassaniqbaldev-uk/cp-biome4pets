<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\CreateReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3c: every report is generated FROM a Test, via either entry point.
 * (AI HTTP is not called in tests — no API key — so ai_* come back as '';
 * the structural wiring, raw-on-test, plan selection, snapshot, slug and the
 * "always linked to a test" invariant are what's asserted here.)
 */
class ReportFromTestTest extends TestCase
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
        // Keep AI generation offline + deterministic: no key => empty interpretations.
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);

        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    /** A test whose raw data fires AMR + Prebiotic → recommends restore-rebalance. */
    private function makeTest(Pet $pet, Client $client, string $orderId, array $overrides = []): Test
    {
        return Test::create(array_merge([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => $orderId,
            'sample_id' => $orderId,
            'report_date' => '2026-06-17',
            'status' => 'results_received',
            'csv_path' => 'csv/x.csv',
            'csv_data' => ['phylum_totals' => ['Bacteroidetes' => 5]],
            'phylum_data' => ['Bacteroidetes' => 5, 'Firmicutes' => 10],
            'diversity_score' => 3.0,
            'species_richness' => 600,
            'dysbiosis_score' => 2.0,
            'microbiome_classification' => 'Imbalanced',
        ], $overrides));
    }

    /** Drive the wizard's real atomic create path. */
    private function handleCreate(array $data): Report
    {
        $page = new CreateReport();
        $m = new \ReflectionMethod($page, 'handleRecordCreation');
        $m->setAccessible(true);

        return $m->invoke($page, $data);
    }

    public function test_A_generate_report_from_an_existing_test_links_and_populates_it(): void
    {
        $plan = Plan::create(['key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => true]);

        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        // Health notes are a dated log; Part 2 freezes the history as of the test
        // date into the snapshot, formatted "date · note".
        $pet->healthNotes()->create(['date' => '2026-06-17', 'note' => 'Itchy skin']);
        $test = $this->makeTest($pet, $client, 'ORD-A');

        $report = ReportGeneration::createReportFromTest($test);

        // Linked to the test, pet/client derived from it.
        $this->assertSame($test->id, $report->test_id);
        $this->assertSame($pet->id, $report->pet_id);
        $this->assertSame($client->id, $report->client_id);
        $this->assertSame('ORD-A', $report->sample_id);

        // Raw read from the test through the Report→Test proxy (no report columns).
        $this->assertSame(['Bacteroidetes' => 5, 'Firmicutes' => 10], $report->phylum_data);
        $this->assertSame(3.0, (float) $report->diversity_score);

        // Plan recommended from the test's raw data; AI columns were written.
        $this->assertSame($plan->id, $report->plan_id);
        $this->assertIsString($report->ai_summary);
        $this->assertArrayHasKey('score_gut_wall', $report->getAttributes());

        // Snapshot frozen, slug built, report_date present (Klaviyo reads it).
        $this->assertSame('Biscuit', $report->pet_snapshot['name']);
        $this->assertSame('2026-06-17 · Itchy skin', $report->pet_snapshot['health_notes']);
        $this->assertNotEmpty($report->slug);
        $this->assertNotNull($report->report_date);

        // The test advanced to report_generated, and the double-guard now holds.
        $this->assertSame('report_generated', $test->fresh()->status);
        $this->assertTrue($test->fresh()->reports()->exists());
    }

    public function test_B_wizard_with_existing_test_attaches_to_it(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o2@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = $this->makeTest($pet, $client, 'ORD-B');

        // Wizard "existing test" path: $data carries existing_test_id, NO raw, no
        // sample_id (prove the report reads it from the test via the proxy).
        $report = $this->handleCreate([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'report_date' => '2026-06-17',
            'status' => 'draft',
            'existing_test_id' => $test->id,
            'test_source' => 'existing',
        ]);

        $this->assertSame($test->id, $report->test_id);
        $this->assertSame('ORD-B', $report->sample_id);                    // via proxy
        $this->assertSame(['Bacteroidetes' => 5, 'Firmicutes' => 10], $report->phylum_data);
        $this->assertSame(1, Test::count(), 'no new test should be created');
        $this->assertSame('report_generated', $test->fresh()->status);
    }

    public function test_C_wizard_with_new_csv_creates_a_test_and_links(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o3@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        // New-CSV path: $data carries raw (as Process CSV would set) + sample_id,
        // NO existing_test_id. A Test must be created and linked.
        $report = $this->handleCreate([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => 'ORD-C',
            'report_date' => '2026-06-17',
            'status' => 'draft',
            'csv_path' => 'csv/new.csv',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 50]],
            'phylum_data' => ['Firmicutes' => 50, 'Bacteroidetes' => 20],
            'diversity_score' => 2.4,
            'species_richness' => 580,
            'dysbiosis_score' => 2.5,
            'microbiome_classification' => 'Imbalanced',
        ]);

        $this->assertSame(1, Test::count());
        $test = Test::first();
        $this->assertSame($report->test_id, $test->id);
        $this->assertSame('ORD-C', $test->order_id);
        $this->assertSame('ORD-C', $test->sample_id);
        // Raw landed on the TEST (not only the report).
        $this->assertSame(['Firmicutes' => 50, 'Bacteroidetes' => 20], $test->phylum_data);
        $this->assertSame(2.4, (float) $test->diversity_score);
    }

    public function test_D_every_creation_path_links_a_test_no_orphan_raw_on_report(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o4@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = $this->makeTest($pet, $client, 'ORD-D');

        $fromTest = ReportGeneration::createReportFromTest($test);
        $fromWizardExisting = $this->handleCreate([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'report_date' => '2026-06-17',
            'status' => 'draft', 'existing_test_id' => $this->makeTest($pet, $client, 'ORD-D2')->id,
        ]);
        $fromWizardNew = $this->handleCreate([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'sample_id' => 'ORD-D3',
            'report_date' => '2026-06-17', 'status' => 'draft',
            'phylum_data' => ['Firmicutes' => 40], 'diversity_score' => 2.1,
        ]);

        // Invariant: no report exists without a linked test.
        foreach ([$fromTest, $fromWizardExisting, $fromWizardNew] as $r) {
            $this->assertNotNull($r->test_id, 'a report was created without a test');
        }
        $this->assertSame(0, Report::whereNull('test_id')->count());
    }
}
