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

    public function test_prompt_reconciles_high_diversity_with_low_richness(): void
    {
        // The exact case that drove the retired panel_contradiction flag: depleted
        // classification (from LOW richness 267 < 400) while diversity 2.89 reads
        // "High". The prompt must instruct the AI to reconcile the two in plain
        // language instead of leaving a mixed message.
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            2.89,
            ['name' => 'Biscuit'],
            ['species_richness' => 267, 'dysbiosis_score' => 1.66, 'microbiome_classification' => 'Imbalanced & Depleted'],
        );

        $this->assertStringContainsString('RECONCILE DIVERSITY vs RICHNESS', $prompt);
        // It names the band it observed and the metric distinction.
        $this->assertStringContainsString('the Shannon diversity reads High', $prompt);
        $this->assertStringContainsString('species richness) is low', $prompt);
        // …and ties it to why the picture reads depleted, factually.
        $this->assertStringContainsString('described as depleted', $prompt);
        $this->assertStringContainsString('do NOT change any number or re-judge any band', $prompt);
    }

    public function test_prompt_reconciliation_absent_when_richness_is_healthy(): void
    {
        // Depleted via LOW DIVERSITY (1.5), richness healthy (700). Not the mixed-
        // message case → no reconciliation block (would be irrelevant/confusing).
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            1.5,
            ['name' => 'Biscuit'],
            ['species_richness' => 700, 'dysbiosis_score' => 1.66, 'microbiome_classification' => 'Imbalanced & Depleted'],
        );

        $this->assertStringNotContainsString('RECONCILE DIVERSITY vs RICHNESS', $prompt);
    }

    public function test_prompt_reconciliation_absent_when_diversity_band_is_low(): void
    {
        // Low richness (267) BUT diversity is also Low (1.5) → the two panels agree
        // (both point to depletion), so there is nothing to reconcile.
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            1.5,
            ['name' => 'Biscuit'],
            ['species_richness' => 267, 'dysbiosis_score' => 1.66, 'microbiome_classification' => 'Imbalanced & Depleted'],
        );

        $this->assertStringNotContainsString('RECONCILE DIVERSITY vs RICHNESS', $prompt);
    }

    public function test_prompt_unchanged_when_no_deterministic_context_supplied(): void
    {
        // Back-compat: the 3-arg form (no deterministic block) adds nothing.
        $bare = (new OpenAiService())->buildInterpretationsPrompt(['Firmicutes' => 50], 2.1, ['name' => 'Rex']);

        $this->assertStringNotContainsString('Species richness', $bare);
        $this->assertStringNotContainsString('Dysbiosis pattern score', $bare);
        $this->assertStringNotContainsString('Overall microbiome classification', $bare);
        $this->assertStringNotContainsString('Keep the WHOLE interpretation consistent with it', $bare);
        // Stage 3: no retained taxa ⇒ no taxa block at all. (The whitelist lock
        // line still names the section generically; the BLOCK heading does not appear.)
        $this->assertStringNotContainsString("Notable taxa detected in THIS pet's sample", $bare);
        $this->assertStringNotContainsString('Describe these taxa factually', $bare);
    }

    public function test_prompt_feeds_top_taxa_and_relaxes_lock_to_a_whitelist(): void
    {
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2],
            2.89,
            ['name' => 'Biscuit'],
            ['top_taxa' => [
                ['name' => 'Fusobacterium', 'rank' => 'genus', 'pct' => 53.78],
                ['name' => 'Fusobacterium perfoetens', 'rank' => 'species', 'pct' => 8.47],
            ]],
        );

        // (a) The pet's specific taxa are fed as fixed facts: names + percentages.
        $this->assertStringContainsString("Notable taxa detected in THIS pet's sample", $prompt);
        $this->assertStringContainsString('Fusobacterium perfoetens (species): 8.47%', $prompt);
        $this->assertStringContainsString('Fusobacterium (genus): 53.78%', $prompt);

        // (b) The lock is now a WHITELIST (name only what you were given), not a ban.
        $this->assertStringContainsString('the specific taxa listed in the "Notable taxa detected" section', $prompt);
        $this->assertStringContainsString('Do NOT name, invent, or infer ANY organism that is not in those lists', $prompt);
        // The old blanket ban wording is gone.
        $this->assertStringNotContainsString('Do not name specific bacteria, species, or any additional taxa', $prompt);

        // (c) The prose is now ACTIVELY asked to reference the notable taxa by name
        //     (strengthened from "may name" → "should reference"), so names reliably appear.
        $this->assertStringContainsString('You SHOULD reference the most notable of these taxa BY NAME', $prompt);
        // Naming is now scoped to vet_summary (the DETAIL paragraph). Previously it
        // asked for the taxa in "the summary and vet_summary" — the two paragraphs are
        // printed one above the other, so that instruction drove the duplicated opening
        // paragraphs the client reported. The summary now stays big-picture.
        $this->assertStringContainsString('especially in vet_summary', $prompt);
        $this->assertStringNotContainsString('especially in the summary and vet_summary', $prompt);

        // (d) CRITICAL: factual only — no verdict words for taxa (no reference ranges).
        $this->assertStringContainsString('Describe them factually', $prompt);
        $this->assertStringContainsString('Do NOT characterise any taxon as high, low, elevated', $prompt);

        // The phylum band determinism is UNCHANGED — its fixed verdict still appears.
        $this->assertStringContainsString('which is HIGH', $prompt); // Fusobacteria 54.4% > high band 25
        $this->assertStringContainsString('state each one EXACTLY as given and never re-judge it', $prompt);
    }
}
