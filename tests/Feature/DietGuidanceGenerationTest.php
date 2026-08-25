<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Test;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step 3: the diet-guidance AI generation + render. generateDietGuidance mirrors the
 * existing AI contract (same key/model, graceful never-throws), is snapshotted into
 * ai_diet_include / ai_diet_avoid alongside the interpretations (regenerated with
 * them), and rendered into the Step-1 boxes on web + PDF with the empty box as the
 * failure/absent fallback. Render is XSS-safe (plain text, escaped then nl2br).
 */
class DietGuidanceGenerationTest extends TestCase
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

    /** A test double that stubs ONLY the HTTP layer, so the real request/parse/validate runs. */
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

    // ── generateDietGuidance: success / failure / missing key / malformed ────

    public function test_returns_include_and_avoid_on_a_successful_response(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope(json_encode([
            'include' => "Good foods for your dog.\nWhite fish: gentle protein.",
            'avoid' => "Foods to avoid for now.\nFatty meats: harder to digest.",
        ])));

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertStringContainsString('White fish', $result['include']);
        $this->assertStringContainsString('Fatty meats', $result['avoid']);
        $this->assertNull($svc->lastErrorCode);
    }

    public function test_returns_empty_and_never_throws_on_api_failure(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp('__FALSE__');   // transport failure

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame(['include' => '', 'avoid' => ''], $result);
        $this->assertSame('api_failed', $svc->lastErrorCode);
    }

    public function test_returns_empty_when_no_api_key(): void
    {
        config(['services.openai.api_key' => '']);   // and no Setting either
        $svc = $this->stubHttp($this->okEnvelope('{}'));   // never reached

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame(['include' => '', 'avoid' => ''], $result);
        $this->assertSame('api_failed', $svc->lastErrorCode);
    }

    public function test_returns_empty_on_malformed_json(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope('not json at all'));

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame(['include' => '', 'avoid' => ''], $result);
        $this->assertSame('json_parse_failed', $svc->lastErrorCode);
    }

    public function test_coerces_non_string_values_to_empty(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        // Model returns an array for "include" instead of a string → coerced to ''.
        $svc = $this->stubHttp($this->okEnvelope(json_encode(['include' => ['bad'], 'avoid' => 'Fine string'])));

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame('', $result['include']);
        $this->assertSame('Fine string', $result['avoid']);
    }

    public function test_strips_markdown_fences(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $svc = $this->stubHttp($this->okEnvelope("```json\n".json_encode(['include' => 'Inc', 'avoid' => 'Avo'])."\n```"));

        $result = $svc->generateDietGuidance(['Proteobacteria' => 30], 2.4);

        $this->assertSame('Inc', $result['include']);
        $this->assertSame('Avo', $result['avoid']);
    }

    // ── Prompt: editable prompt + rules from Settings are in the request ────

    public function test_prompt_includes_the_editable_prompt_and_rules(): void
    {
        $prompt = (new OpenAiService)->buildDietGuidancePrompt(['Proteobacteria' => 30], 2.4);

        // The editable prompt (default) and the default rules are both present.
        $this->assertStringContainsString(Setting::DIET_GUIDANCE_PROMPT_DEFAULT, $prompt);
        $this->assertStringContainsString('High Proteobacteria', $prompt);
        $this->assertStringContainsString('White fish, bone broth, pumpkin', $prompt);
        // ...and the dog's findings.
        $this->assertStringContainsString('Proteobacteria: 30%', $prompt);
    }

    public function test_prompt_reflects_edited_settings(): void
    {
        Setting::set(Setting::DIET_GUIDANCE_PROMPT, 'EDITED DIET PROMPT.');
        Setting::set(Setting::DIET_GUIDANCE_RULES, json_encode([
            ['finding' => 'Custom finding X', 'recommend' => 'Turkey', 'reason' => 'Gentle'],
        ]));

        $prompt = (new OpenAiService)->buildDietGuidancePrompt(['Firmicutes' => 40], 2.1);

        $this->assertStringContainsString('EDITED DIET PROMPT.', $prompt);
        $this->assertStringContainsString('Custom finding X => Turkey (Gentle)', $prompt);
        $this->assertStringNotContainsString('High Proteobacteria', $prompt);   // defaults not used
    }

    // ── Wiring: interpretationColumns carries the keys; create/regenerate persist ──

    private function seedTest(): array
    {
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex', 'diet' => 'Kibble']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Proteobacteria' => 20],
            'diversity_score' => 2.4, 'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => ['Firmicutes' => 45]],
        ]);

        return [$test];
    }

    /** Bind a fake service returning non-empty interpretation + diet copy. */
    private function bindFakeAi(string $include, string $avoid): void
    {
        $this->app->bind(OpenAiService::class, function () use ($include, $avoid) {
            return new class($include, $avoid) extends OpenAiService
            {
                public function __construct(private string $inc, private string $avo)
                {
                }

                public function generateReportInterpretations(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = [], ?int $reportId = null): array
                {
                    return [
                        'summary' => 'Summary.', 'goal' => 'Goal.',
                        'bacteroidetes_interpretation' => 'B.', 'firmicutes_interpretation' => 'F.',
                        'fusobacteria_interpretation' => 'Fu.', 'proteobacteria_interpretation' => 'P.',
                        'diversity_interpretation' => 'D.', 'vet_summary' => 'V.', 'recommended_actions' => 'A.',
                    ];
                }

                public function generateDietGuidance(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = [], ?int $reportId = null): array
                {
                    return ['include' => $this->inc, 'avoid' => $this->avo];
                }
            };
        });
    }

    public function test_interpretation_columns_always_carry_the_diet_keys(): void
    {
        // No key → empty AI, but the two keys must still be present (empty strings),
        // so create + regenerate always persist the columns.
        $cols = ReportGeneration::interpretationColumns(['Firmicutes' => 45], 2.4, null);
        $this->assertArrayHasKey('ai_diet_include', $cols);
        $this->assertArrayHasKey('ai_diet_avoid', $cols);
        $this->assertSame('', $cols['ai_diet_include']);
        $this->assertSame('', $cols['ai_diet_avoid']);
    }

    public function test_generation_persists_the_diet_copy_into_the_columns(): void
    {
        $this->bindFakeAi('INCLUDE COPY', 'AVOID COPY');
        [$test] = $this->seedTest();

        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        $this->assertSame('INCLUDE COPY', $report->ai_diet_include);
        $this->assertSame('AVOID COPY', $report->ai_diet_avoid);
    }

    public function test_regenerate_refreshes_the_diet_copy(): void
    {
        $this->bindFakeAi('FIRST INCLUDE', 'FIRST AVOID');
        [$test] = $this->seedTest();
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();
        $this->assertSame('FIRST INCLUDE', $report->ai_diet_include);

        // Re-bind with new copy and regenerate — the columns refresh with the rest.
        $this->bindFakeAi('SECOND INCLUDE', 'SECOND AVOID');
        $result = ReportGeneration::regenerateReport($report->fresh());

        $this->assertTrue($result['ok']);
        $this->assertSame('SECOND INCLUDE', $report->fresh()->ai_diet_include);
        $this->assertSame('SECOND AVOID', $report->fresh()->ai_diet_avoid);
    }

    public function test_generation_with_no_key_persists_empty_and_does_not_break(): void
    {
        [$test] = $this->seedTest();   // no fake bound, no key → empty AI

        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        $this->assertSame('', $report->ai_diet_include);
        $this->assertSame('', $report->ai_diet_avoid);
    }

    // ── Render: web + PDF show the copy when present, empty box when absent ──

    private function reportWith(?string $include, ?string $avoid): Report
    {
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 45]],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Rex'],
            'ai_diet_include' => $include, 'ai_diet_avoid' => $avoid,
        ]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_web_and_pdf_render_the_copy_when_present(): void
    {
        $report = $this->reportWith("Good foods here.\nWhite fish: gentle.", "Avoid these.\nFatty meats: heavy.");

        $web = view('report.show', ['report' => $report])->render();
        $pdf = view('report.pdf', ['report' => $report])->render();

        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            $this->assertStringContainsString('White fish: gentle.', $html, $where);
            $this->assertStringContainsString('Fatty meats: heavy.', $html, $where);
            // Line breaks rendered.
            $this->assertStringContainsString('<br', $html, $where);
        }
    }

    public function test_whole_section_absent_when_both_fields_empty(): void
    {
        foreach ([[null, null], ['', ''], ['   ', "\n "]] as [$inc, $avo]) {
            $report = $this->reportWith($inc, $avo);

            $web = view('report.show', ['report' => $report])->render();
            $pdf = view('report.pdf', ['report' => $report])->render();

            foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
                // The ENTIRE section is gone — no heading, no static scaffold, no boxes.
                $this->assertStringNotContainsString("Your Dog's Personalised Diet Guidance", $html, $where);
                $this->assertStringNotContainsString('What your dog can digest', $html, $where);
                $this->assertStringNotContainsString('For dogs that are sensitive to change', $html, $where);
                $this->assertStringNotContainsString('dashed #C7D8EA', $html, $where);
            }
        }
    }

    public function test_section_present_when_either_field_is_populated(): void
    {
        foreach ([['Only include copy.', null], [null, 'Only avoid copy.']] as [$inc, $avo]) {
            $report = $this->reportWith($inc, $avo);
            $web = view('report.show', ['report' => $report])->render();
            $pdf = view('report.pdf', ['report' => $report])->render();

            foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
                // The whole section renders (partial generation still shows it).
                $this->assertStringContainsString("Your Dog's Personalised Diet Guidance", $html, $where);
                $this->assertStringContainsString('For dogs that are sensitive to change', $html, $where);
                // The populated side's copy is shown.
                $this->assertStringContainsString($inc ?? $avo, $html, $where);
                // No broken empty placeholder for the missing side.
                $this->assertStringNotContainsString('dashed #C7D8EA', $html, $where);
            }
        }
    }

    public function test_render_is_xss_safe(): void
    {
        $report = $this->reportWith('<script>alert(1)</script> and safe text', 'plain');

        $web = view('report.show', ['report' => $report])->render();

        // The raw script tag must NOT appear; it is escaped.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $web);
        $this->assertStringContainsString('&lt;script&gt;', $web);
        $this->assertStringContainsString('and safe text', $web);
    }

    // ── Rule gating: only genuinely-matching rules fire ─────────────────────

    public function test_high_phylum_rule_fires_only_above_the_high_threshold(): void
    {
        $svc = new OpenAiService;
        $rules = [['finding' => 'High Proteobacteria', 'recommend' => 'White fish', 'reason' => 'x']];

        // Proteobacteria high threshold = 18. 5.12% (Oscar) → does NOT fire, and a
        // computable-but-not-firing rule is DROPPED (not offered as general either).
        $oscar = $svc->gateDietRules($rules, ['Proteobacteria' => 5.12], 2.4, []);
        $this->assertSame([], $oscar['matched']);
        $this->assertSame([], $oscar['general']);

        // 20.85% (Piper) → fires.
        $piper = $svc->gateDietRules($rules, ['Proteobacteria' => 20.85], 2.4, []);
        $this->assertCount(1, $piper['matched']);
        $this->assertSame('High Proteobacteria', $piper['matched'][0]['finding']);
    }

    public function test_low_phylum_rule_fires_only_below_the_low_threshold(): void
    {
        $svc = new OpenAiService;
        $rules = [['finding' => 'Low Bacteroidetes', 'recommend' => 'x', 'reason' => 'y']]; // low = 10
        $this->assertCount(1, $svc->gateDietRules($rules, ['Bacteroidetes' => 4], 2.4, [])['matched']);
        $this->assertSame([], $svc->gateDietRules($rules, ['Bacteroidetes' => 25], 2.4, [])['matched']);
    }

    public function test_diversity_richness_dysbiosis_gating(): void
    {
        $svc = new OpenAiService;
        // Low diversity: < 1.9 fires; 2.4 (Medium) does not.
        $this->assertCount(1, $svc->gateDietRules([['finding' => 'Low diversity', 'recommend' => 'x', 'reason' => 'y']], [], 1.5, [])['matched']);
        $this->assertSame([], $svc->gateDietRules([['finding' => 'Low diversity', 'recommend' => 'x', 'reason' => 'y']], [], 2.4, [])['matched']);
        // High dysbiosis: > 0.5 fires.
        $this->assertCount(1, $svc->gateDietRules([['finding' => 'High dysbiosis', 'recommend' => 'x', 'reason' => 'y']], [], 2.4, ['dysbiosis_score' => 15.16])['matched']);
        $this->assertSame([], $svc->gateDietRules([['finding' => 'High dysbiosis', 'recommend' => 'x', 'reason' => 'y']], [], 2.4, ['dysbiosis_score' => 0.4])['matched']);
        // Low richness: < 400 fires.
        $this->assertCount(1, $svc->gateDietRules([['finding' => 'Low richness', 'recommend' => 'x', 'reason' => 'y']], [], 2.4, ['species_richness' => 270])['matched']);
    }

    public function test_uncomputable_and_unresolvable_rules_go_to_general_never_matched(): void
    {
        $svc = new OpenAiService;
        $rules = [
            ['finding' => 'High protein fermentation', 'recommend' => 'a', 'reason' => 'b'], // no metric → general
            ['finding' => 'High Sutterella', 'recommend' => 'a', 'reason' => 'b'],           // not a banded phylum → general
            ['finding' => 'High Prevotella', 'recommend' => 'a', 'reason' => 'b'],           // banded, but no value present → fail safe → general
        ];
        $g = $svc->gateDietRules($rules, ['Proteobacteria' => 5], 2.4, []);
        $this->assertSame([], $g['matched']);
        $this->assertCount(3, $g['general']);
    }

    public function test_prompt_hands_over_only_matched_rules_as_applicable(): void
    {
        // Oscar-like (Proteobacteria 5.12, diversity 2.2): the High Proteobacteria rule
        // (its recommend is the only source of "White fish") is dropped, so "White fish"
        // must not appear anywhere in the prompt.
        $oscarPrompt = (new OpenAiService)->buildDietGuidancePrompt(
            ['Proteobacteria' => 5.12, 'Firmicutes' => 26.06], 2.2, [], ['species_richness' => 270, 'dysbiosis_score' => 0.52],
        );
        $this->assertStringContainsString('APPLICABLE RULES', $oscarPrompt);
        $this->assertStringNotContainsString('White fish', $oscarPrompt);
        // The old loose framing that caused the bug is gone.
        $this->assertStringNotContainsString('interpret sensibly, not mechanically', $oscarPrompt);

        // Piper-like (Proteobacteria 20.85): White fish IS applicable.
        $piperPrompt = (new OpenAiService)->buildDietGuidancePrompt(['Proteobacteria' => 20.85], 2.4, [], []);
        $applicable = substr(
            $piperPrompt,
            strpos($piperPrompt, 'APPLICABLE RULES'),
            strpos($piperPrompt, 'GENERAL GUIDANCE') - strpos($piperPrompt, 'APPLICABLE RULES'),
        );
        $this->assertStringContainsString('White fish', $applicable);
    }

    public function test_gating_never_throws_on_malformed_rules(): void
    {
        $svc = new OpenAiService;
        // Missing keys / odd shapes must be tolerated (fail safe to general, no throw).
        $g = $svc->gateDietRules([['finding' => ''], ['recommend' => 'x'], ['finding' => 'Zzz Unknownus']], ['Proteobacteria' => 5], 2.4, []);
        $this->assertIsArray($g['matched']);
        $this->assertIsArray($g['general']);
        $this->assertSame([], $g['matched']);
    }
}
