<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Filament\Resources\TestResource;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression cover for the Test-refactor linkage bugs: after the raw lab columns
 * were dropped from `reports` (they live on the linked Test), three Filament
 * subsystems bypassed the getAttribute proxy. These assert the fixes:
 *   Bug 1/2 — EditReport re-hydrates the wizard's test-owned fields + test-source.
 *   Bug 2   — the existing-test select keeps the current (reported) test listed.
 *   Bug 3   — a test is globally searchable via TestResource, even with no report.
 */
class ReportTestLinkageFixesTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
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

    /** A pet + a test that owns the raw lab data, with a report linked to it. */
    private function seedReportedTest(string $sampleId = 'ORD-EDIT'): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o' . uniqid() . '@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $test = Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => $sampleId,
            'sample_id' => $sampleId,
            'report_date' => '2026-05-12',
            'phylum_data' => ['Firmicutes' => 40, 'Bacteroidetes' => 20],
            'diversity_score' => 2.1,
            'species_richness' => 500,
            'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Imbalanced',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40]],
        ]);

        $report = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'test_id' => $test->id,
            'status' => 'draft',
        ]);

        return [$client, $pet, $test, $report];
    }

    /** Bug 1 + 2: opening a report to edit fills the test-owned fields and selects the test. */
    public function test_edit_report_hydrates_test_owned_fields_and_selects_the_test(): void
    {
        [, , $test, $report] = $this->seedReportedTest('ORD-EDIT');

        Livewire::test(EditReport::class, ['record' => $report->getKey()])
            ->assertFormSet([
                'test_source' => 'existing',
                'existing_test_id' => $test->id,
                'sample_id' => 'ORD-EDIT',
                'report_date' => '2026-05-12',
                'microbiome_classification' => 'Imbalanced',
                'diversity_score' => 2.1,
            ]);
    }

    /** Bug 2: the existing-test select keeps the currently-linked (reported) test listed. */
    public function test_existing_test_select_includes_current_test_on_edit(): void
    {
        [, $pet, $test] = $this->seedReportedTest('ORD-CUR');

        // A second test for the same pet with no report — always selectable.
        $awaiting = Test::create([
            'pet_id' => $pet->id,
            'client_id' => $pet->client_id,
            'order_id' => 'ORD-FREE',
            'sample_id' => 'ORD-FREE',
            'report_date' => '2026-05-01',
        ]);

        // With the current (reported) test passed in, it stays in the options
        // (so its preselected value resolves to a label) alongside the free one.
        $withCurrent = ReportResource::existingTestOptions($pet->id, $test->id);
        $this->assertArrayHasKey($test->id, $withCurrent);
        $this->assertArrayHasKey($awaiting->id, $withCurrent);

        // Without it (the create flow), a reported test is correctly excluded.
        $createFlow = ReportResource::existingTestOptions($pet->id, null);
        $this->assertArrayNotHasKey($test->id, $createFlow);
        $this->assertArrayHasKey($awaiting->id, $createFlow);
    }

    /** Bug 3: a test with no report is findable in global search via TestResource. */
    public function test_test_is_globally_searchable_even_without_a_report(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'g@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $test = Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'NCS-LONE',
            'sample_id' => 'NCS-LONE',
        ]);

        $results = TestResource::getGlobalSearchResults('NCS-LONE');

        $this->assertGreaterThanOrEqual(1, $results->count(), 'test not surfaced in global search');
        $this->assertSame('NCS-LONE', $results->first()->title);
        // A non-blank URL is required, else Filament drops the result.
        $this->assertNotEmpty(TestResource::getGlobalSearchResultUrl($test));
    }
}
