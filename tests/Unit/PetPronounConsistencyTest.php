<?php

namespace Tests\Unit;

use App\Services\OpenAiService;
use App\Support\PetFindings;
use App\Support\PetPronouns;
use PHPUnit\Framework\TestCase;

/**
 * Pet pronoun consistency. The pet's recorded sex is handed to BOTH generation
 * prompts as a fixed pronoun instruction so the prose can't drift gender within a
 * report (the "she at the top, he in the recommendation tips" bug). The tips are
 * produced by a SEPARATE plan-copy call, so its findings payload must carry the
 * guidance too. Unknown sex ⇒ name / they-their, never a guessed gender.
 */
class PetPronounConsistencyTest extends TestCase
{
    // ── The shared helper ───────────────────────────────────────────────────
    public function test_helper_normalises_sex_and_picks_pronouns(): void
    {
        $this->assertSame('female', PetPronouns::normalise('Female'));
        $this->assertSame('male', PetPronouns::normalise('male'));
        $this->assertNull(PetPronouns::normalise(''));
        $this->assertNull(PetPronouns::normalise(null));
        $this->assertNull(PetPronouns::normalise('unknown'));

        $this->assertStringContainsString('she/her', PetPronouns::instruction('Female'));
        $this->assertStringContainsString('he/him', PetPronouns::instruction('Male'));
        $this->assertStringContainsString('they/their', PetPronouns::instruction(null));
        $this->assertStringContainsString('do not guess or assume a gender', strtolower(PetPronouns::instruction(null)));
    }

    // ── The main interpretations prompt (summary / vet_summary / per-microbe) ──
    public function test_interpretations_prompt_carries_correct_pronouns_for_each_sex(): void
    {
        $build = fn (?string $sex): string => (new OpenAiService())->buildInterpretationsPrompt(
            ['Firmicutes' => 50], 2.1, ['name' => 'Louie', 'sex' => $sex],
        );

        $female = $build('Female');
        $this->assertStringContainsString('This pet is female. Use she/her', $female);
        // It is bound to EVERY field, not just one section.
        $this->assertStringContainsString('PRONOUNS:', $female);
        $this->assertStringContainsString('every per-phylum interpretation', $female);
        $this->assertStringNotContainsString('Use he/him', $female);

        $male = $build('Male');
        $this->assertStringContainsString('This pet is male. Use he/him', $male);
        $this->assertStringNotContainsString('Use she/her', $male);

        $unknown = $build(null);
        $this->assertStringContainsString('sex is not recorded', $unknown);
        $this->assertStringContainsString('they/their', $unknown);
        $this->assertStringNotContainsString('Use she/her', $unknown);
        $this->assertStringNotContainsString('Use he/him', $unknown);
    }

    // ── The SEPARATE plan-copy call (the recommendation/tips that drifted) ────
    public function test_plan_findings_payload_carries_sex_and_pronoun_guidance(): void
    {
        $female = PetFindings::build(['pet_name' => 'Louie', 'sex' => 'Female']);
        $this->assertSame('female', $female['sex']);
        $this->assertStringContainsString('she/her', $female['pronoun_guidance']);
        $this->assertStringContainsString('any tips', $female['pronoun_guidance']);

        $male = PetFindings::build(['pet_name' => 'Rex', 'sex' => 'Male']);
        $this->assertSame('male', $male['sex']);
        $this->assertStringContainsString('he/him', $male['pronoun_guidance']);

        // Unknown sex → marked unknown, guidance forbids guessing a gender.
        $unknown = PetFindings::build(['pet_name' => 'Bean']);
        $this->assertSame('unknown', $unknown['sex']);
        $this->assertStringContainsString('they/their', $unknown['pronoun_guidance']);
        $this->assertStringContainsString('not recorded', $unknown['pronoun_guidance']);
    }

    // ── Both prompts agree (no drift between report body and tips) ────────────
    public function test_both_prompts_use_the_same_guidance_for_a_pet(): void
    {
        $report = (new OpenAiService())->buildInterpretationsPrompt(
            ['Firmicutes' => 50], 2.1, ['name' => 'Louie', 'sex' => 'Female'],
        );
        $tips = PetFindings::build(['pet_name' => 'Louie', 'sex' => 'Female'])['pronoun_guidance'];

        // The exact same female instruction string appears in both.
        $this->assertStringContainsString($tips, $report);
    }
}
