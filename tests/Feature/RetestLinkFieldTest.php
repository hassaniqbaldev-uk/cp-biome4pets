<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Services\CsvParserService;
use App\Support\ReportGeneration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin "Retest of" link field + its save hook. Setting the link on the edit form
 * and saving freezes retest_comparison (via ReportGeneration::retestComparisonFor);
 * clearing it nulls the snapshot. The Select's options are scoped to the same pet's
 * other reports. Uses the real Teddy fixtures as the two tests' CSVs.
 */
class RetestLinkFieldTest extends TestCase
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

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
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

    /** A pet with an original (S9) report and a retest (S12) report, both drafts. */
    private function seedPair(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        return [$pet, $client, $original, $retest];
    }

    public function test_setting_the_link_on_save_freezes_the_comparison(): void
    {
        [, , $original, $retest] = $this->seedPair();
        $this->assertNull($retest->retest_comparison);

        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->set('data.retest_of_report_id', $original->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $comparison = $retest->fresh()->retest_comparison;
        $this->assertIsArray($comparison);
        $this->assertEqualsWithDelta(3.16, $comparison['overview']['original']['diversity'], 0.005);
        $this->assertEqualsWithDelta(2.02, $comparison['overview']['retest']['diversity'], 0.005);
        $this->assertSame(463, $comparison['overview']['original']['species_richness']);
        $this->assertSame(215, $comparison['overview']['retest']['species_richness']);
        $this->assertTrue($retest->fresh()->isRetest());

        // The Key Changes default is seeded alongside the comparison.
        $kc = $retest->fresh()->retest_key_changes;
        $this->assertIsArray($kc);
        $this->assertContains('Fusobacterium', array_column($kc['increases'], 'name'));
        $this->assertContains('Bacteroides', array_column($kc['decreases'], 'name'));
    }

    public function test_clearing_the_link_on_save_nulls_the_comparison(): void
    {
        [, , $original, $retest] = $this->seedPair();

        // Link + snapshot + seeded key changes first.
        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));
        $this->assertIsArray($retest->fresh()->retest_comparison);
        $this->assertIsArray($retest->fresh()->retest_key_changes);

        // Now clear it through the form save.
        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->set('data.retest_of_report_id', null)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($retest->fresh()->retest_comparison);
        $this->assertNull($retest->fresh()->retest_key_changes, 'clearing the link nulls key changes too');
        $this->assertFalse($retest->fresh()->isRetest());
    }

    public function test_options_are_scoped_to_the_same_pet_and_exclude_self(): void
    {
        [$pet, , $original, $retest] = $this->seedPair();

        // A different pet (same client) with its own report — must never appear.
        $otherPet = Pet::create(['client_id' => $pet->client_id, 'name' => 'Rex']);
        $otherTest = $this->makeTest($otherPet, $pet->client, 'teddy_original_S9.csv', 'retest/x'.uniqid().'.csv');
        $otherReport = ReportGeneration::createReportFromTest($otherTest->fresh())->fresh();

        $options = ReportResource::retestOfOptions($pet->id, $retest->id);

        // The same pet's OTHER report (the original) is offered.
        $this->assertArrayHasKey($original->id, $options);
        // Never this report itself…
        $this->assertArrayNotHasKey($retest->id, $options);
        // …and never a different pet's report.
        $this->assertArrayNotHasKey($otherReport->id, $options);

        // Blank pet → no options.
        $this->assertSame([], ReportResource::retestOfOptions(null));
    }
}
