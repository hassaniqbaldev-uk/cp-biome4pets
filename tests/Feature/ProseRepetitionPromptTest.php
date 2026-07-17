<?php

namespace Tests\Feature;

use App\Services\OpenAiService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The client reported the "Your Dog's Personal Summary" section repeating itself in
 * the opening paragraphs, and the prose generally being long-winded / not objective.
 *
 * Cause: that section prints ai_summary and vet_summary as CONSECUTIVE paragraphs
 * under one heading, and their prompt briefs were near-duplicates (both "4-5 sentence"
 * warm owner-facing summaries of the same findings, both naming the pet, both
 * forward-looking), with two rules actively pushing the SAME content into BOTH.
 *
 * These tests lock the fix: each field has a distinct job, repetition is explicitly
 * forbidden, padding/hedging is discouraged — while the deterministic grounding
 * (band determinism, coherence, diversity-vs-richness reconciliation) survives intact.
 */
class ProseRepetitionPromptTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function prompt(array $deterministic = []): string
    {
        return (new OpenAiService)->buildInterpretationsPrompt(
            ['Bacteroidetes' => 40, 'Firmicutes' => 35, 'Fusobacteria' => 15, 'Proteobacteria' => 10],
            3.2,
            ['name' => 'Rex', 'breed' => 'Labrador'],
            $deterministic,
        );
    }

    public function test_prompt_explicitly_forbids_repeating_across_fields(): void
    {
        $prompt = $this->prompt();

        $this->assertStringContainsString('NO REPETITION ACROSS FIELDS', $prompt);
        $this->assertStringContainsString('Every field must do its OWN job and add NEW information', $prompt);
        $this->assertStringContainsString('never write the same point, the same figure or the same sentence twice', strtolower($prompt));
        // Rephrasing the same point is still repetition — closes the obvious loophole.
        $this->assertStringContainsString('Rephrasing the same point in different words still counts as repeating it', $prompt);
    }

    public function test_summary_and_vet_summary_have_distinct_non_overlapping_jobs(): void
    {
        $prompt = $this->prompt();

        // summary = headline only, explicitly NOT the taxa/figures.
        $this->assertStringContainsString('The HEADLINE paragraph', $prompt);
        $this->assertStringContainsString('Do NOT list the individual taxa or recite the per-phylum figures here', $prompt);

        // vet_summary = the detail, and must add rather than restate.
        $this->assertStringContainsString('The DETAIL paragraph', $prompt);
        $this->assertStringContainsString('it must ADD to it and never restate it', $prompt);
        $this->assertStringContainsString('Do NOT repeat the overall verdict, any figure, or any sentence already given in "summary"', $prompt);

        // Both are told they are printed adjacently — the fact the model previously lacked.
        $this->assertStringContainsString('printed directly underneath this paragraph under the same heading', $prompt);
        $this->assertStringContainsString('printed DIRECTLY BELOW "summary" under the same heading', $prompt);
    }

    public function test_prompt_asks_for_objective_prose_and_forbids_padding(): void
    {
        $prompt = $this->prompt();

        $this->assertStringContainsString('BE DIRECT AND OBJECTIVE', $prompt);
        $this->assertStringContainsString('Cut hedging and filler', $prompt);
        $this->assertStringContainsString('if there is less to say, write less', $prompt);
        // Length is now an upper bound, not a quota to fill.
        $this->assertStringContainsString('Up to 4 sentences', $prompt);
        $this->assertStringNotContainsString('A 4-5 sentence overall summary', $prompt);
        // …but warmth is explicitly preserved (this is customer-facing).
        $this->assertStringContainsString('Being direct does NOT mean being cold or clinical', $prompt);
        $this->assertStringContainsString('stay warm, human and reassuring', $prompt);
    }

    /** The deterministic grounding must survive the rewrite untouched. */
    public function test_deterministic_grounding_rules_are_preserved(): void
    {
        $prompt = $this->prompt(['microbiome_classification' => 'Imbalanced & Depleted']);

        // Band determinism.
        $this->assertStringContainsString('state each one EXACTLY as given and never re-judge it', $prompt);
        $this->assertStringContainsString('are FIXED and already computed from the figures', $prompt);
        // Numbers.
        $this->assertStringContainsString('Do not invent or alter any numbers', $prompt);
        // Coherence rule (classification supplied).
        $this->assertStringContainsString('Keep the WHOLE interpretation consistent with it', $prompt);
    }

    /** The diversity-vs-richness reconciliation still fires — and is now asked for
     *  ONCE rather than in both adjacent paragraphs. */
    public function test_reconciliation_rule_survives_and_is_stated_once(): void
    {
        $prompt = $this->prompt([
            'species_richness' => 267,                       // low (< 400)
            'microbiome_classification' => 'Imbalanced & Depleted',
        ]);                                                   // diversity 3.2 → High band

        $this->assertStringContainsString('RECONCILE DIVERSITY vs RICHNESS', $prompt);
        $this->assertStringContainsString('the Shannon diversity reads High', $prompt);
        $this->assertStringContainsString('do NOT change any number or re-judge any band', $prompt);
        // Explained once, in the summary — no longer duplicated into vet_summary.
        $this->assertStringContainsString('explain it plainly ONCE', $prompt);
        $this->assertStringContainsString('do not repeat the explanation in vet_summary', $prompt);
        $this->assertStringNotContainsString('explain it plainly in the summary and vet_summary', $prompt);
    }

    /** The AI still must not produce the six health-insight scores (now deterministic). */
    public function test_the_six_scores_are_still_not_requested(): void
    {
        $prompt = $this->prompt();

        foreach ([
            'score_gut_wall', 'score_skin_allergy', 'score_behaviour_mood',
            'score_gut_barrier', 'score_gas_digestive', 'score_stress_resilience',
        ] as $key) {
            $this->assertStringNotContainsString($key, $prompt);
        }
    }
}
