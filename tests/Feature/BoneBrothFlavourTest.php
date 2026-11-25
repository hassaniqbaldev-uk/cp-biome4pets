<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step A: the AI-recommended bone broth flavour, folded into the SAME diet-guidance
 * call (no second AI call). A nullable column on the report, generated as a third
 * JSON key (chicken|beef|either), coerced to the neutral default on anything
 * missing/invalid, admin-overridable before publish, and NOT yet shown to customers.
 */
class BoneBrothFlavourTest extends TestCase
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
    }

    /** A test double that stubs ONLY the HTTP layer, so the real parse/validate runs. */
    private function stubHttp(string $rawResponse): OpenAiService
    {
        return new class($rawResponse) extends OpenAiService
        {
            public function __construct(private string $raw)
            {
            }

            protected function requestChatCompletion(string $apiKey, string $payload): string|false
            {
                return $this->raw === '__FALSE__' ? false : $this->raw;
            }
        };
    }

    private function okEnvelope(string $content): string
    {
        return json_encode(['choices' => [['message' => ['content' => $content]]]]);
    }

    // ── Column ──────────────────────────────────────────────────────────────

    public function test_column_exists_is_nullable_and_existing_reports_unaffected(): void
    {
        $this->assertTrue(Schema::hasColumn('reports', 'bone_broth_flavour'));

        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $test = Test::create(['pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-01-01']);
        // A report created WITHOUT the field is null (unaffected / additive).
        $report = Report::create(['client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => 'draft']);
        $this->assertNull($report->fresh()->bone_broth_flavour);
    }

    // ── generateDietGuidance: the third key, coercion, no regression ─────────

    public function test_valid_flavour_is_stored(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope(json_encode([
            'include' => "Good foods.\nWhite fish: gentle.",
            'avoid' => "Avoid these.\nChicken: owner says he won't eat it.",
            'bone_broth_flavour' => 'beef',
        ])));

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame('beef', $result['bone_broth_flavour']);
        // include/avoid parsing is unchanged by the new key.
        $this->assertStringContainsString('White fish: gentle.', $result['include']);
        $this->assertStringContainsString('Chicken: owner says', $result['avoid']);
    }

    public function test_missing_flavour_key_coerces_to_either(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope(json_encode([
            'include' => 'Inc', 'avoid' => 'Avo',   // no bone_broth_flavour
        ])));

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame('either', $result['bone_broth_flavour']);
        // include/avoid still parsed.
        $this->assertSame('Inc', $result['include']);
        $this->assertSame('Avo', $result['avoid']);
    }

    public function test_invalid_flavour_value_coerces_to_either(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope(json_encode([
            'include' => 'Inc', 'avoid' => 'Avo', 'bone_broth_flavour' => 'pork',
        ])));

        $this->assertSame('either', $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4)['bone_broth_flavour']);
    }

    public function test_flavour_is_case_insensitive(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope(json_encode([
            'include' => 'Inc', 'avoid' => 'Avo', 'bone_broth_flavour' => 'Chicken',
        ])));

        $this->assertSame('chicken', $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4)['bone_broth_flavour']);
    }

    public function test_api_failure_defaults_flavour_to_either(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp('__FALSE__');

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame(['include' => '', 'avoid' => '', 'bone_broth_flavour' => 'either'], $result);
        $this->assertSame('api_failed', $svc->lastErrorCode);
    }

    public function test_prompt_instructs_the_third_key(): void
    {
        $prompt = (new OpenAiService)->buildDietGuidancePrompt(['Proteobacteria' => 30], 2.4);

        $this->assertStringContainsString('bone_broth_flavour', $prompt);
        $this->assertStringContainsString('never recommend a protein this dog should avoid', $prompt);
    }

    // ── Wiring: stored on the report, first test AND retest ─────────────────

    private function seedTest(): Test
    {
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex', 'diet' => 'Kibble']);

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Proteobacteria' => 20],
            'diversity_score' => 2.4, 'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => ['Firmicutes' => 45]],
        ]);
    }

    private function bindFakeAi(string $flavour): void
    {
        $this->app->bind(OpenAiService::class, function () use ($flavour) {
            return new class($flavour) extends OpenAiService
            {
                public function __construct(private string $flavour)
                {
                }

                public function generateReportInterpretations(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = [], ?int $reportId = null): array
                {
                    return [
                        'summary' => 'S.', 'goal' => 'G.', 'bacteroidetes_interpretation' => 'B.',
                        'firmicutes_interpretation' => 'F.', 'fusobacteria_interpretation' => 'Fu.',
                        'proteobacteria_interpretation' => 'P.', 'diversity_interpretation' => 'D.',
                        'vet_summary' => 'V.', 'recommended_actions' => 'A.',
                    ];
                }

                public function generateDietGuidance(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = [], ?int $reportId = null): array
                {
                    return ['include' => 'INC', 'avoid' => 'AVO', 'bone_broth_flavour' => $this->flavour];
                }
            };
        });
    }

    public function test_interpretation_columns_always_carry_the_flavour_key(): void
    {
        // No key bound → empty AI, but the flavour column must still be present and valid.
        $cols = ReportGeneration::interpretationColumns(['Firmicutes' => 45], 2.4, null);
        $this->assertArrayHasKey('bone_broth_flavour', $cols);
        $this->assertSame('either', $cols['bone_broth_flavour']);
    }

    public function test_first_test_report_receives_the_generated_flavour(): void
    {
        $this->bindFakeAi('beef');
        $report = ReportGeneration::createReportFromTest($this->seedTest()->fresh())->fresh();

        $this->assertSame('beef', $report->bone_broth_flavour);
        // Diet copy still stored alongside (no regression).
        $this->assertSame('INC', $report->ai_diet_include);
        $this->assertSame('AVO', $report->ai_diet_avoid);
    }

    public function test_retest_report_receives_the_generated_flavour(): void
    {
        $this->bindFakeAi('chicken');
        $original = ReportGeneration::createReportFromTest($this->seedTest()->fresh())->fresh();

        // A second test on the same pet, created as a retest.
        $retestTest = Test::create([
            'pet_id' => $original->pet_id, 'client_id' => $original->client_id,
            'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-09-17',
            'phylum_data' => ['Firmicutes' => 40, 'Proteobacteria' => 10], 'diversity_score' => 2.6,
            'species_richness' => 540, 'dysbiosis_score' => 0.3, 'microbiome_classification' => 'Balanced',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40]],
        ]);
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();
        $retest->update(['retest_of_report_id' => $original->id]);

        $this->assertSame('chicken', $retest->fresh()->bone_broth_flavour);
    }

    public function test_regenerate_refreshes_the_flavour(): void
    {
        $this->bindFakeAi('beef');
        $report = ReportGeneration::createReportFromTest($this->seedTest()->fresh())->fresh();
        $this->assertSame('beef', $report->bone_broth_flavour);

        $this->bindFakeAi('chicken');
        $result = ReportGeneration::regenerateReport($report->fresh());
        $this->assertTrue($result['ok']);
        $this->assertSame('chicken', $report->fresh()->bone_broth_flavour);
    }

    // ── Admin override persists through Save ────────────────────────────────

    public function test_admin_override_persists_through_save(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->bindFakeAi('beef');
        $report = ReportGeneration::createReportFromTest($this->seedTest()->fresh())->fresh();
        $this->assertSame('beef', $report->bone_broth_flavour);

        // Admin overrides beef → chicken in the form and saves.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertFormFieldExists('bone_broth_flavour')
            ->set('data.bone_broth_flavour', 'chicken')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('chicken', $report->fresh()->bone_broth_flavour);
    }

    // ── No customer-facing display ──────────────────────────────────────────

    public function test_public_report_and_pdf_do_not_render_the_flavour(): void
    {
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $test = Test::create(['pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => ['Firmicutes' => 45]]]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => 'published',
            'pet_snapshot' => ['name' => 'Rex'], 'ai_diet_include' => 'Inc copy', 'ai_diet_avoid' => 'Avo copy',
            'bone_broth_flavour' => 'beef',
        ]);
        $loaded = $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);

        foreach (['report.show', 'report.pdf'] as $view) {
            $html = view($view, ['report' => $loaded])->render();
            // The flavour value must NOT surface anywhere customer-facing yet.
            $this->assertStringNotContainsString('bone_broth_flavour', $html, $view);
            $this->assertStringNotContainsString('Bone Broth', $html, $view);
        }
    }
}
