<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\OpenAiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-section AI directives are injected INLINE next to the right field
 * instructions in the single interpretations prompt, additively and
 * append-only. With every directive blank, the prompt is byte-for-byte the
 * same as before the feature existed.
 */
class AiDirectiveInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate on an in-memory sqlite DB so we never touch dev data.
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

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    /** A representative set of inputs reused across the cases. */
    private function buildPrompt(): string
    {
        return (new OpenAiService())->buildInterpretationsPrompt(
            ['Bacteroidetes' => 40, 'Firmicutes' => 35, 'Fusobacteria' => 15, 'Proteobacteria' => 10],
            3.2,
            ['name' => 'Rex', 'breed' => 'Labrador'],
        );
    }

    public function test_all_blank_directives_produce_the_baseline_prompt(): void
    {
        $baseline = $this->buildPrompt();

        // No "Admin guidance" clause and no global directive block anywhere.
        $this->assertStringNotContainsString('Admin guidance for this field:', $baseline);
        $this->assertStringNotContainsString('Additional instructions from the administrator', $baseline);

        // Setting the keys to blank explicitly is identical to leaving them unset.
        foreach ([
            Setting::OPENAI_PROMPT_DIRECTIVES,
            Setting::OPENAI_DIRECTIVE_SUMMARY,
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY,
            Setting::OPENAI_DIRECTIVE_PHYLA,
            Setting::OPENAI_DIRECTIVE_SCORES,
        ] as $key) {
            Setting::set($key, '');
        }

        $this->assertSame($baseline, $this->buildPrompt());
    }

    public function test_summary_directive_is_appended_to_the_summary_bullet_only(): void
    {
        Setting::set(Setting::OPENAI_DIRECTIVE_SUMMARY, 'Mention the home environment.');

        $prompt = $this->buildPrompt();

        // Injected once, immediately after the summary bullet's instruction.
        $this->assertStringContainsString(
            'what it means for this pet. Admin guidance for this field: Mention the home environment.',
            $prompt,
        );
        // Exactly one occurrence — not leaking onto other bullets.
        $this->assertSame(1, substr_count($prompt, 'Admin guidance for this field:'));
        // The vet summary line is untouched.
        $this->assertStringContainsString(
            'It MUST address the pet by name when a name is provided.' . "\n",
            $prompt,
        );
    }

    public function test_vet_summary_directive_is_appended_to_the_vet_summary_bullet_only(): void
    {
        Setting::set(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, 'Reference faecal scoring.');

        $prompt = $this->buildPrompt();

        $this->assertStringContainsString(
            'It MUST address the pet by name when a name is provided. Admin guidance for this field: Reference faecal scoring.',
            $prompt,
        );
        $this->assertSame(1, substr_count($prompt, 'Admin guidance for this field:'));
    }

    public function test_phyla_directive_is_appended_to_all_five_phylum_and_diversity_bullets(): void
    {
        Setting::set(Setting::OPENAI_DIRECTIVE_PHYLA, 'Always name a relevant prebiotic fibre.');

        $prompt = $this->buildPrompt();

        // 4 phyla + diversity = 5 inline injections, and nowhere else.
        $this->assertSame(
            5,
            substr_count($prompt, 'Admin guidance for this field: Always name a relevant prebiotic fibre.'),
        );
        $this->assertSame(5, substr_count($prompt, 'Admin guidance for this field:'));

        // Anchored to each phylum/diversity bullet's own trailing text.
        foreach ([
            'what Bacteroidetes does',
            'what Firmicutes does',
            'what Fusobacteria does',
            'what Proteobacteria does',
            'what diversity means for gut health',
        ] as $marker) {
            $this->assertStringContainsString($marker, $prompt);
        }
    }

    public function test_prompt_no_longer_asks_the_ai_for_the_six_scores(): void
    {
        // Stage 2: the six health-insight scores are computed deterministically, so
        // the prompt must not request them, and the scores directive is now inert.
        Setting::set(Setting::OPENAI_DIRECTIVE_SCORES, 'Be conservative; prefer Low when uncertain.');

        $prompt = $this->buildPrompt();

        foreach ([
            'score_gut_wall', 'score_skin_allergy', 'score_behaviour_mood',
            'score_gut_barrier', 'score_gas_digestive', 'score_stress_resilience',
        ] as $scoreKey) {
            $this->assertStringNotContainsString($scoreKey, $prompt);
        }
        // The (now removed) score block gave the directive nowhere to attach.
        $this->assertStringNotContainsString('Be conservative; prefer Low when uncertain.', $prompt);
        $this->assertSame(0, substr_count($prompt, 'Admin guidance for this field:'));
    }

    public function test_global_directive_still_appends_after_the_whole_prompt(): void
    {
        Setting::set(Setting::OPENAI_PROMPT_DIRECTIVES, 'House style: keep it under 80 words per field.');

        $prompt = $this->buildPrompt();

        $this->assertStringContainsString(
            "Additional instructions from the administrator (follow these as well, while still returning only the JSON object):\n"
            . 'House style: keep it under 80 words per field.',
            $prompt,
        );
        // The global block lands at the very end, after the JSON-only rule.
        $this->assertStringEndsWith('House style: keep it under 80 words per field.', $prompt);
    }

    public function test_directives_compose_without_disturbing_safety_rules(): void
    {
        Setting::set(Setting::OPENAI_DIRECTIVE_SUMMARY, 'Summary steer.');
        Setting::set(Setting::OPENAI_DIRECTIVE_PHYLA, 'Phyla steer.');
        Setting::set(Setting::OPENAI_DIRECTIVE_SCORES, 'Scores steer.');
        Setting::set(Setting::OPENAI_PROMPT_DIRECTIVES, 'Global steer.');

        $prompt = $this->buildPrompt();

        // 1 summary + 5 phyla = 6 inline clauses. The scores directive is now inert
        // (the AI no longer produces the six deterministic scores), so it adds none.
        $this->assertSame(6, substr_count($prompt, 'Admin guidance for this field:'));
        $this->assertStringNotContainsString('Scores steer.', $prompt);

        // Safety / format rules survive untouched.
        $this->assertStringContainsString('Do NOT use em dashes', $prompt);
        $this->assertStringContainsString('plain British English', $prompt);
        $this->assertStringContainsString('Return ONLY the JSON object, no markdown or extra text.', $prompt);
        $this->assertStringContainsString('Additional instructions from the administrator', $prompt);
    }
}
