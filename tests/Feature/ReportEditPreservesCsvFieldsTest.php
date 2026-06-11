<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReportEditPreservesCsvFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated in-memory sqlite with the full schema — never touches MySQL.
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

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_editing_and_saving_a_report_preserves_csv_derived_fields(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);

        $report = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => 'CSV-TEST',
            'report_date' => '2026-06-11',
            'status' => 'draft',
            // CSV-derived values that MUST survive an edit-save.
            'phylum_data' => ['Firmicutes' => 50, 'Bacteroidetes' => 20],
            'diversity_score' => 9.99,
            'species_richness' => 555,
            'dysbiosis_score' => 7.77,
            'microbiome_classification' => 'TESTCLASS',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 50]],
        ]);

        // Drive the REAL Filament edit page save (no field changes — just save).
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $report->fresh();

        fwrite(STDERR, "\nAFTER SAVE: diversity_score=" . var_export($fresh->diversity_score, true)
            . " species_richness=" . var_export($fresh->species_richness, true)
            . " dysbiosis_score=" . var_export($fresh->dysbiosis_score, true)
            . " classification=" . var_export($fresh->microbiome_classification, true)
            . " phylum_data=" . (empty($fresh->phylum_data) ? 'EMPTY' : 'set') . "\n");

        $this->assertSame(9.99, (float) $fresh->diversity_score, 'diversity_score was wiped');
        $this->assertSame(555, (int) $fresh->species_richness, 'species_richness was wiped');
        $this->assertSame(7.77, (float) $fresh->dysbiosis_score, 'dysbiosis_score was wiped');
        $this->assertSame('TESTCLASS', $fresh->microbiome_classification, 'classification was wiped');
        $this->assertNotEmpty($fresh->phylum_data, 'phylum_data was wiped');
    }

    public function test_guard_restores_csv_fields_even_when_form_data_carries_nulls(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner2@example.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Bo']);

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'sample_id' => 'GUARD', 'report_date' => '2026-06-11', 'status' => 'draft',
            'phylum_data' => ['Firmicutes' => 40], 'diversity_score' => 3.33,
            'species_richness' => 700, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Stable',
        ]);

        $page = new EditReport();
        $rp = new \ReflectionProperty($page, 'record');
        $rp->setAccessible(true);
        $rp->setValue($page, $report);

        // Hostile input: form data explicitly carries null/empty CSV fields.
        $method = new \ReflectionMethod($page, 'mutateFormDataBeforeSave');
        $method->setAccessible(true);
        $out = $method->invoke($page, [
            'pet_id' => $pet->id, 'sample_id' => 'GUARD',
            'phylum_data' => [], 'diversity_score' => null, 'species_richness' => null,
            'dysbiosis_score' => null, 'microbiome_classification' => null,
        ]);

        $this->assertSame(3.33, (float) $out['diversity_score'], 'guard did not restore diversity_score');
        $this->assertSame(700, (int) $out['species_richness'], 'guard did not restore species_richness');
        $this->assertSame(0.4, (float) $out['dysbiosis_score'], 'guard did not restore dysbiosis_score');
        $this->assertSame('Stable', $out['microbiome_classification'], 'guard did not restore classification');
        $this->assertNotEmpty($out['phylum_data'], 'guard did not restore phylum_data');
    }
}
