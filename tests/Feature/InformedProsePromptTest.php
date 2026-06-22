<?php

namespace Tests\Feature;

use App\Services\OpenAiService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FIX (informed prose) — the interpretation prompt now receives species richness,
 * the dysbiosis score and the overall classification as FIXED grounded facts, plus
 * a coherence instruction, so the prose can no longer read more reassuring than the
 * badge. These are existing values surfaced read-only — no computation/scale change.
 */
class InformedProsePromptTest extends TestCase
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

    public function test_prompt_includes_richness_dysbiosis_classification_and_coherence_rule(): void
    {
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            2.89,
            ['name' => 'Biscuit'],
            ['species_richness' => 267, 'dysbiosis_score' => 1.66, 'microbiome_classification' => 'Imbalanced & Depleted'],
        );

        $this->assertStringContainsString('Species richness', $prompt);
        $this->assertStringContainsString('267', $prompt);
        $this->assertStringContainsString('Dysbiosis pattern score', $prompt);
        $this->assertStringContainsString('1.66', $prompt);
        $this->assertStringContainsString('Overall microbiome classification: Imbalanced & Depleted', $prompt);
        // The coherence instruction is present when a classification is supplied.
        $this->assertStringContainsString('Keep the WHOLE interpretation consistent with it', $prompt);
        // Existing grounding guardrails remain intact.
        $this->assertStringContainsString('Do not invent or alter any numbers', $prompt);
    }

    public function test_prompt_unchanged_when_no_deterministic_context_supplied(): void
    {
        // Back-compat: the 3-arg form (no deterministic block) adds nothing.
        $bare = (new OpenAiService())->buildInterpretationsPrompt(['Firmicutes' => 50], 2.1, ['name' => 'Rex']);

        $this->assertStringNotContainsString('Species richness', $bare);
        $this->assertStringNotContainsString('Dysbiosis pattern score', $bare);
        $this->assertStringNotContainsString('Overall microbiome classification', $bare);
        $this->assertStringNotContainsString('Keep the WHOLE interpretation consistent with it', $bare);
    }
}
