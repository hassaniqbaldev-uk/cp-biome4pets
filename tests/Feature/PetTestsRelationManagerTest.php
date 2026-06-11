<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource\RelationManagers\TestsRelationManager;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3b: a Test can be created standalone under a pet (order id + CSV),
 * with the raw lab data parsed onto it, and NO report yet.
 */
class PetTestsRelationManagerTest extends TestCase
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

    public function test_a_test_can_be_created_under_a_pet_with_parsed_data_and_no_report(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('csv/lab.csv', implode("\n", [
            'Phylum,Species,%_hits',
            'Firmicutes,Lactobacillus reuteri,30',
            'Bacteroidetes,Bacteroides fragilis,20',
            'Fusobacteria,Fusobacterium mortiferum,15',
            'Proteobacteria,Escherichia coli,10',
        ]));

        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        // Drive the SAME data-prep the relation manager's Create action uses.
        $rm = new TestsRelationManager();
        $rm->ownerRecord = $pet;
        $m = new \ReflectionMethod($rm, 'prepareTestData');
        $m->setAccessible(true);

        $prepared = $m->invoke($rm, [
            'order_id' => 'ORD-100',
            'csv_path' => 'csv/lab.csv',
            'report_date' => '2026-06-17',
            'status' => 'results_received',
        ]);

        // Persist via the relationship exactly as the CreateAction would.
        $test = $pet->tests()->create($prepared);

        // Identity + ownership auto-derived.
        $this->assertSame('ORD-100', $test->order_id);
        $this->assertSame('ORD-100', $test->sample_id);   // mirrors order_id
        $this->assertSame($pet->id, $test->pet_id);
        $this->assertSame($client->id, $test->client_id);
        $this->assertSame('results_received', $test->status);

        // Raw lab data parsed ONTO the test.
        $this->assertIsArray($test->phylum_data);
        $this->assertArrayHasKey('Firmicutes', $test->phylum_data);
        $this->assertNotNull($test->diversity_score);
        $this->assertSame(4, $test->species_richness);
        $this->assertNotNull($test->microbiome_classification);
        $this->assertIsArray($test->csv_data);

        // The whole point: it sits standalone with NO report.
        $this->assertSame(0, $test->reports()->count());
        $this->assertTrue($pet->tests()->whereDoesntHave('reports')->exists());

        // And it shows up in the pet's Tests list.
        $this->assertSame(1, $pet->tests()->count());
    }
}
