<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use App\Support\ReportQualityValidator;
use App\Support\RetestSummaryFacts;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The grounded AI retest summary (step 7g): generateRetestSummary mirrors the diet-guidance
 * contract (same key/model, graceful never-throws), is snapshotted into retest_summary only
 * for a retest and refreshed on regenerate, and its prose is scanned by the unknown-taxon
 * guardrail with the whitelist extended by the retest allow-list. Mirrors
 * DietGuidanceGenerationTest's HTTP-stub approach.
 */
class RetestSummaryGenerationTest extends TestCase
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
    }

    /** Stub ONLY the HTTP layer so the real request/parse/validate runs. */
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

    private function sixKeyJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'conclusion' => 'Overall, Teddy is making progress.',
            'started' => 'At the first test, Fusobacterium was moderate.',
            'improved' => 'Firmicutes has moved in a better direction.',
            'attention' => 'Bacteroidetes still needs attention.',
            'meaning' => 'The gut is more balanced than before.',
            'recommend' => 'Continue the suggested plan and review with the team.',
        ], $overrides));
    }

    /** Bind a method-level fake AI so a full regenerate reaches its persist path. */
    private function bindFakeAi(?array $summary = null): void
    {
        $summary ??= [
            'conclusion' => 'Overall progress.', 'started' => 'St.', 'improved' => 'Im.',
            'attention' => 'At.', 'meaning' => 'Me.', 'recommend' => 'Re.',
        ];
        $this->app->bind(OpenAiService::class, fn () => new class($summary) extends OpenAiService
        {
            public function __construct(private array $summary)
            {
            }

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

            public function generateRetestSummary(\App\Models\Report $report, ?int $reportId = null): array
            {
                return $report->isRetest()
                    ? $this->summary
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

    /** An original + a linked, frozen retest. Returns [original, retest]. */
    private function seedLinkedRetest(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));

        return [$original->fresh(), $retest->fresh()];
    }

    // ── generateRetestSummary: success / failure / missing key / malformed ──

    public function test_returns_the_six_keys_on_a_successful_response(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        [, $retest] = $this->seedLinkedRetest();

        $svc = $this->stubHttp($this->okEnvelope($this->sixKeyJson()));
        $result = $svc->generateRetestSummary($retest);

        $this->assertSame(
            ['conclusion', 'started', 'improved', 'attention', 'meaning', 'recommend'],
            array_keys($result),
        );
        $this->assertStringContainsString('progress', $result['conclusion']);
        $this->assertNull($svc->lastErrorCode);
    }

    public function test_returns_empty_and_never_throws_on_api_failure(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        [, $retest] = $this->seedLinkedRetest();

        $svc = $this->stubHttp('__FALSE__');
        $result = $svc->generateRetestSummary($retest);

        $this->assertSame(array_fill_keys(['conclusion', 'started', 'improved', 'attention', 'meaning', 'recommend'], ''), $result);
        $this->assertSame('api_failed', $svc->lastErrorCode);
    }

    public function test_returns_empty_when_no_api_key(): void
    {
        config(['services.openai.api_key' => '']);
        [, $retest] = $this->seedLinkedRetest();

        $svc = $this->stubHttp($this->okEnvelope($this->sixKeyJson()));
        $result = $svc->generateRetestSummary($retest);

        $this->assertSame('', $result['conclusion']);
        $this->assertSame('api_failed', $svc->lastErrorCode);
    }

    public function test_returns_empty_on_malformed_json(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        [, $retest] = $this->seedLinkedRetest();

        $svc = $this->stubHttp($this->okEnvelope('not json at all'));
        $result = $svc->generateRetestSummary($retest);

        $this->assertSame('', $result['meaning']);
        $this->assertSame('json_parse_failed', $svc->lastErrorCode);
    }

    public function test_a_non_retest_report_returns_empty(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        $svc = $this->stubHttp($this->okEnvelope($this->sixKeyJson()));
        $this->assertSame('', $svc->generateRetestSummary($report)['conclusion']);
    }

    // ── prompt grounding ──

    public function test_prompt_includes_the_allow_list_and_the_facts(): void
    {
        [, $retest] = $this->seedLinkedRetest();

        $payload = RetestSummaryFacts::build($retest);
        $prompt = (new OpenAiService)->buildRetestSummaryPrompt($payload);

        // Allow-list grounding present, and real grounded taxa are named.
        $this->assertStringContainsString('You may name ONLY these taxa:', $prompt);
        $this->assertStringContainsString('Fusobacterium', $prompt);  // a key-change genus
        $this->assertStringContainsString('Bacteroides', $prompt);    // a key-change genus
        $this->assertStringContainsString('Akkermansia', $prompt);    // a specialist
        // Facts present: an overview figure and a label.
        $this->assertStringContainsString('OVERVIEW', $prompt);
        $this->assertStringContainsString('SPECIALIST RECOVERY', $prompt);
        $this->assertStringContainsString('Do not invent figures', $prompt);
    }

    // ── storage via the generation flow ──

    public function test_regenerate_stores_the_summary_for_a_retest(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        [, $retest] = $this->seedLinkedRetest();
        $this->bindFakeAi();

        $result = ReportGeneration::regenerateReport($retest->fresh());
        $this->assertTrue($result['ok']);

        $summary = $retest->fresh()->retest_summary;
        $this->assertIsArray($summary);
        $this->assertSame(
            ['conclusion', 'started', 'improved', 'attention', 'meaning', 'recommend'],
            array_keys($summary),
        );
        $this->assertNotSame('', $summary['conclusion']);
    }

    public function test_regenerate_leaves_a_non_retest_summary_null(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();
        $this->bindFakeAi();

        ReportGeneration::regenerateReport($report->fresh());
        $this->assertNull($report->fresh()->retest_summary);
    }

    // ── validator: grounded vs ungrounded taxa in the summary ──

    public function test_validator_flags_an_ungrounded_taxon_in_the_summary(): void
    {
        [, $retest] = $this->seedLinkedRetest();
        $allow = RetestSummaryFacts::allowList($retest);

        $verdict = ReportQualityValidator::validate([
            // ai_summary keeps the output non-empty so the taxon scan runs; the
            // ungrounded organism sits in the summary's "conclusion" field.
            'interpretations' => [
                'ai_summary' => 'A normal interpretation paragraph.',
                // Staphylococcus is NOT among Teddy's key changes / specialists / major
                // phyla, so it is ungrounded and must flag.
                'conclusion' => 'Staphylococcus has taken over the gut.',
            ],
            'retest_allow_list' => $allow,
        ]);

        $codes = array_column($verdict['issues'], 'code');
        $this->assertContains('unknown_taxon', $codes, 'Staphylococcus is not grounded and must flag');
    }

    public function test_validator_does_not_flag_a_grounded_taxon_in_the_summary(): void
    {
        [, $retest] = $this->seedLinkedRetest();
        $allow = RetestSummaryFacts::allowList($retest);

        $verdict = ReportQualityValidator::validate([
            'interpretations' => [
                'ai_summary' => 'A normal interpretation paragraph.',
                'improved' => 'Fusobacterium and Bacteroides both shifted.',
            ],
            'retest_allow_list' => $allow,
        ]);

        $codes = array_column($verdict['issues'], 'code');
        $this->assertNotContains('unknown_taxon', $codes, 'grounded taxa must not flag');
    }
}
