<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\CreateReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The retest create flow (step 11): the "Retest of" link lives on wizard step 1, and a
 * single create of a linked retest runs the FULL pipeline in one pass — freeze comparison,
 * seed key changes, default hide_commerce, and generate the AI summary — with no separate
 * regenerate. A normal report (no link) is created exactly as before and writes none of the
 * retest fields. Uses the real Teddy fixtures.
 */
class RetestCreateFlowTest extends TestCase
{
    public const SUMMARY = [
        'conclusion' => 'CONCLUSION-XYZ', 'started' => 'St.', 'improved' => 'Im.',
        'attention' => 'At.', 'meaning' => 'Me.', 'recommend' => 'Re.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        config(['services.openai.api_key' => 'sk-test', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->bindFakeAi();
    }

    /** Method-level fake AI so interpretations + the retest summary return known content. */
    private function bindFakeAi(): void
    {
        $this->app->bind(OpenAiService::class, fn () => new class extends OpenAiService
        {
            public function generateReportInterpretations(array $p, float $d, array $c = [], array $x = [], ?int $r = null): array
            {
                return [
                    'summary' => 'S.', 'goal' => 'G.', 'bacteroidetes_interpretation' => 'B.',
                    'firmicutes_interpretation' => 'F.', 'fusobacteria_interpretation' => 'Fu.',
                    'proteobacteria_interpretation' => 'P.', 'diversity_interpretation' => 'D.',
                    'vet_summary' => 'V.', 'recommended_actions' => 'A.',
                ];
            }

            public function generateDietGuidance(array $p, float $d, array $c = [], array $x = [], ?int $r = null): array
            {
                return ['include' => 'Inc.', 'avoid' => 'Avo.'];
            }

            public function generateRetestSummary(Report $report, ?int $reportId = null): array
            {
                return $report->isRetest()
                    ? RetestCreateFlowTest::SUMMARY
                    : array_fill_keys(['conclusion', 'started', 'improved', 'attention', 'meaning', 'recommend'], '');
            }
        });
    }

    private function makeTest(Pet $pet, Client $client, string $fixture, string $relative): Test
    {
        Storage::disk('local')->put($relative, file_get_contents(base_path("tests/fixtures/retest/{$fixture}")));
        $parsed = (new CsvParserService)->parse(base_path("tests/fixtures/retest/{$fixture}"));

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-01-01',
            'csv_path' => $relative,
            'csv_data' => ['top_taxa' => $parsed['top_taxa'], 'insight_taxa' => $parsed['insight_taxa']],
            'phylum_data' => $parsed['phylum_totals'],
            'diversity_score' => $parsed['diversity_score'],
            'species_richness' => $parsed['species_richness'],
            'dysbiosis_score' => $parsed['dysbiosis_score'],
            'microbiome_classification' => $parsed['microbiome_classification'],
        ]);
    }

    /** Create the form data for the existing-test wizard path. */
    private function formData(Client $client, Pet $pet, Test $test, Plan $plan, ?int $retestOf): array
    {
        return [
            'test_source' => 'existing',
            'existing_test_id' => $test->id,
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => $test->sample_id,
            'report_date' => '2026-02-01',
            'status' => 'draft',
            'retest_of_report_id' => $retestOf,
            'plan_id' => $plan->id,
            'catalog_product_ids' => [],
            'steps' => [[
                'type' => 'prose', 'title' => 'Step 1', 'stage_label' => 'Phase 1', 'body' => 'x',
            ]],
        ];
    }

    public function test_retest_field_is_in_step_one_and_editors_gate_on_the_link(): void
    {
        $component = Livewire::test(CreateReport::class);

        // The link field renders on the create form (moved to step 1).
        $component->assertFormFieldExists('retest_of_report_id');

        // Editors are hidden with no link, and shown once a link is set.
        $component->assertFormFieldIsHidden('retest_summary.conclusion')
            ->assertFormFieldIsHidden('retest_key_changes.increases');
    }

    public function test_creating_a_linked_retest_builds_everything_in_one_pass(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $plan = Plan::create(['key' => 'p-'.uniqid(), 'name' => 'Plan A', 'enabled' => true]);

        // Original report (the link target) + a report-less retest test to select.
        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        Livewire::test(CreateReport::class)
            ->fillForm($this->formData($client, $pet, $retestTest, $plan, $original->id))
            ->call('create')
            ->assertHasNoFormErrors();

        $report = Report::where('test_id', $retestTest->id)->firstOrFail();

        // ONE pass populated all four.
        $this->assertTrue($report->isRetest());

        $this->assertIsArray($report->retest_comparison);
        $this->assertEqualsWithDelta(3.16, $report->retest_comparison['overview']['original']['diversity'], 0.005);
        $this->assertEqualsWithDelta(2.02, $report->retest_comparison['overview']['retest']['diversity'], 0.005);

        $this->assertIsArray($report->retest_key_changes);
        $this->assertContains('Fusobacterium', array_column($report->retest_key_changes['increases'], 'name'));

        $this->assertIsArray($report->retest_summary);
        $this->assertSame('CONCLUSION-XYZ', $report->retest_summary['conclusion']);

        // Plan populated, hide_commerce defaulted on.
        $this->assertSame($plan->id, $report->plan_id);
        $this->assertTrue($report->steps()->exists());
        $this->assertTrue($report->hide_commerce);
    }

    public function test_creating_a_normal_report_writes_no_retest_fields(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $plan = Plan::create(['key' => 'p-'.uniqid(), 'name' => 'Plan A', 'enabled' => true]);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/n'.uniqid().'.csv');

        Livewire::test(CreateReport::class)
            ->fillForm($this->formData($client, $pet, $test, $plan, null)) // no link
            ->call('create')
            ->assertHasNoFormErrors();

        $report = Report::where('test_id', $test->id)->firstOrFail();

        $this->assertFalse($report->isRetest());
        $this->assertNull($report->retest_comparison);
        $this->assertNull($report->retest_key_changes);
        $this->assertNull($report->retest_summary);
        $this->assertFalse((bool) $report->hide_commerce);
        // The normal report still built its plan.
        $this->assertSame($plan->id, $report->plan_id);
    }
}
