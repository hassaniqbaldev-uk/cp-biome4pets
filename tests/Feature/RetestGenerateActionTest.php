<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
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
 * The explicit "Generate AI from selected test" action, on an ALREADY-SAVED retest (edit page),
 * also rebuilds the retest pipeline — freeze/refresh the comparison, seed Key Changes (only when
 * null), and (re)generate the grounded AI summary — then fills the summary boxes, same as the
 * create flow. An ordinary Save never regenerates the summary (so a team edit survives). A
 * non-retest report's generate action is unchanged and writes no retest fields. Teddy fixtures.
 */
class RetestGenerateActionTest extends TestCase
{
    /** Sentinel the fake AI writes for a retest — distinct from any human edit. */
    public const GEN = [
        'conclusion' => 'GEN-CONCLUSION-XYZ', 'started' => 'GEN-St.', 'improved' => 'GEN-Im.',
        'attention' => 'GEN-At.', 'meaning' => 'GEN-Me.', 'recommend' => 'GEN-Re.',
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

    /** Method-level fake AI: the retest summary returns the known sentinel (empty for a non-retest). */
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
                    ? RetestGenerateActionTest::GEN
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

    /**
     * A linked retest with the comparison + Key Changes frozen but the AI summary NOT yet
     * generated (retest_summary stays null) — the "before Generate AI" state. Returns the retest.
     */
    private function seedLinkedRetest(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));

        return $retest->fresh();
    }

    /** Resolve the live "generate_from_test" form action off the edit page's cached form. */
    private function generateAction($component)
    {
        return $component->instance()
            ->getCachedForms()['form']
            ->getComponent('data.generate_from_testAction')
            ->getAction('generate_from_test');
    }

    public function test_generate_on_an_existing_retest_fills_the_summary_and_key_changes(): void
    {
        $retest = $this->seedLinkedRetest();

        // Before: no AI summary yet (the boxes would be empty).
        $this->assertNull($retest->retest_summary);

        $component = Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()]);
        $this->generateAction($component)->call();

        // The retest build ran and persisted the grounded summary + Key Changes straight to
        // the DB — so the summary is durable the moment Generate AI is clicked, with or without
        // a later Save (the same way the create flow and afterSave freeze persist immediately).
        $fresh = $retest->fresh();
        $this->assertIsArray($fresh->retest_summary);
        $this->assertSame('GEN-CONCLUSION-XYZ', $fresh->retest_summary['conclusion']);
        $this->assertIsArray($fresh->retest_key_changes);
        $this->assertNotEmpty($fresh->retest_key_changes['increases']);

        // And the summary box in the form is filled in, where before it was empty — so the
        // admin sees the generated text immediately and the next Save carries it.
        $this->assertSame('GEN-CONCLUSION-XYZ', $component->get('data.retest_summary.conclusion'));
        $this->assertSame('GEN-Re.', $component->get('data.retest_summary.recommend'));
    }

    public function test_generate_on_a_non_retest_writes_no_retest_fields(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/n'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        $this->assertFalse($report->isRetest());

        $component = Livewire::test(EditReport::class, ['record' => $report->getRouteKey()]);
        $this->generateAction($component)->call();

        $fresh = $report->fresh();
        $this->assertNull($fresh->retest_comparison);
        $this->assertNull($fresh->retest_key_changes);
        $this->assertNull($fresh->retest_summary);
    }

    public function test_an_ordinary_save_does_not_regenerate_the_summary(): void
    {
        $retest = $this->seedLinkedRetest();
        // A team member's curated summary — the fake would return GEN-CONCLUSION-XYZ instead.
        $retest->update(['retest_summary' => [
            'conclusion' => 'HUMAN-EDIT', 'started' => 'h1', 'improved' => 'h2',
            'attention' => 'h3', 'meaning' => 'h4', 'recommend' => 'h5',
        ]]);

        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        // Untouched by a normal save — never regenerated to the AI sentinel.
        $this->assertSame('HUMAN-EDIT', $retest->fresh()->retest_summary['conclusion']);
    }
}
