<?php

namespace Tests\Unit;

use App\Services\OpenAiService;
use App\Support\ReportContent;
use App\Support\ReportQualityValidator;
use PHPUnit\Framework\TestCase;

/**
 * Data-correctness: where a phylum value sits relative to its band is decided in
 * CODE (arithmetic), never by the AI. The prompt is handed the determined band as
 * a fixed fact, and the validator deterministically flags any prose that
 * contradicts the computed band (the Fusobacteria-2.97%-called-"normal" bug).
 */
class MicrobeBandDeterminismTest extends TestCase
{
    public function test_band_verdict_is_computed_deterministically(): void
    {
        // Fusobacteria band is low=10, high=25.
        $this->assertSame('low', ReportContent::phylumBandVerdict('Fusobacteria', 2.97)['band']);   // the bug case
        $this->assertSame('low', ReportContent::phylumBandVerdict('Fusobacteria', 9.99)['band']);
        $this->assertSame('within', ReportContent::phylumBandVerdict('Fusobacteria', 10)['band']);  // boundary (inclusive)
        $this->assertSame('within', ReportContent::phylumBandVerdict('Fusobacteria', 18)['band']);  // the target
        $this->assertSame('within', ReportContent::phylumBandVerdict('Fusobacteria', 25)['band']);  // boundary (inclusive)
        $this->assertSame('high', ReportContent::phylumBandVerdict('Fusobacteria', 25.01)['band']);

        // No band defined → null (e.g. a phylum without a card).
        $this->assertNull(ReportContent::phylumBandVerdict('Verrucomicrobia', 5));

        // The single-source bands feed the chart's microbes() too (no drift).
        $this->assertSame(['low' => 10, 'target' => 18, 'high' => 25], ReportContent::PHYLUM_BANDS['Fusobacteria']);
    }

    public function test_prompt_states_the_determined_band_and_forbids_re_judging(): void
    {
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Fusobacteria' => 2.97, 'Firmicutes' => 60],
            2.4,
            ['name' => 'Biscuit'],
        );

        // The low value is stated as LOW (not left for the AI to guess).
        $this->assertStringContainsString('Fusobacteria is 2.97%, which is LOW (below the typical range of 10% to 25%).', $prompt);
        $this->assertStringContainsString('Firmicutes is 60%, which is HIGH', $prompt);
        // The instruction tells the AI to state the GIVEN band, never decide it.
        $this->assertStringContainsString('State each level EXACTLY as given', $prompt);
        $this->assertStringContainsString('NEVER describe a value given as LOW as normal, within range', $prompt);
        // The old "AI decides the range" instruction is gone.
        $this->assertStringNotContainsString("whether it's within/above/below the healthy range", $prompt);
    }

    public function test_validator_flags_a_low_value_described_as_normal(): void
    {
        $verdict = ReportQualityValidator::validate($this->ctx([
            'ai_fusobacteria_interpretation' => 'Fusobacteria is within the normal range at 2.97%, which is reassuring.',
        ], ['Fusobacteria' => 2.97]));

        $codes = array_column($verdict['issues'], 'code');
        $this->assertContains('band_contradiction', $codes);
        $this->assertTrue($verdict['needs_review']);   // deterministic → drives review

        $flag = collect($verdict['issues'])->firstWhere('code', 'band_contradiction');
        $this->assertSame('deterministic', $flag['tier']);
        $this->assertStringContainsString('2.97%', $flag['detail']);
        $this->assertStringContainsString('low relative to the typical range', $flag['detail']);
    }

    /**
     * Regression: a client report showed Proteobacteria at 1.44% described as
     * "within a typical range" when it should read LOW (band low=5). Confirms the
     * fix covers Proteobacteria end-to-end: verdict → prompt fact → validator flag.
     */
    public function test_proteobacteria_low_value_is_determined_stated_and_flagged(): void
    {
        // 1. Verdict: 1.44% < low(5) → low. (Band is low=5, target=9, high=18.)
        $this->assertSame('low', ReportContent::phylumBandVerdict('Proteobacteria', 1.44)['band']);
        $this->assertSame(['low' => 5, 'target' => 9, 'high' => 18], ReportContent::PHYLUM_BANDS['Proteobacteria']);

        // 2. Prompt: Proteobacteria is fed to the model as a fixed LOW fact.
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(
            ['Proteobacteria' => 1.44, 'Firmicutes' => 30],
            2.4,
            ['name' => 'Dog2'],
        );
        $this->assertStringContainsString('Proteobacteria is 1.44%, which is LOW (below the typical range of 5% to 18%).', $prompt);

        // 3. Validator: prose calling that low value "within a typical range" is flagged.
        $verdict = ReportQualityValidator::validate($this->ctx([
            'ai_proteobacteria_interpretation' => 'Proteobacteria is within a typical range at 1.44%, which is reassuring.',
        ], ['Proteobacteria' => 1.44]));

        $flag = collect($verdict['issues'])->firstWhere('code', 'band_contradiction');
        $this->assertNotNull($flag, 'Expected a band_contradiction flag for Proteobacteria.');
        $this->assertTrue($verdict['needs_review']);
        $this->assertStringContainsString('Proteobacteria', $flag['detail']);
        $this->assertStringContainsString('1.44%', $flag['detail']);
        $this->assertStringContainsString('low relative to the typical range', $flag['detail']);
    }

    public function test_validator_does_not_flag_prose_consistent_with_the_band(): void
    {
        // Correct: a low value described as low; a within value described as within.
        $verdict = ReportQualityValidator::validate($this->ctx([
            'ai_fusobacteria_interpretation' => 'Fusobacteria is low at 2.97%, below the typical range, so we will work to raise it back within the normal range over the coming weeks.',
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes is within the typical range at 22%, which is a healthy sign.',
        ], ['Fusobacteria' => 2.97, 'Bacteroidetes' => 22]));

        $this->assertNotContains('band_contradiction', array_column($verdict['issues'], 'code'));
    }

    /** A valid baseline context with one or two phylum prose fields overridden. */
    private function ctx(array $proseOverrides, array $phylumTotals): array
    {
        $interp = array_merge([
            'ai_summary' => 'Summary.',
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes commentary.',
            'ai_firmicutes_interpretation' => 'Firmicutes commentary.',
            'ai_fusobacteria_interpretation' => 'Fusobacteria commentary.',
            'ai_proteobacteria_interpretation' => 'Proteobacteria commentary.',
            'ai_diversity_interpretation' => 'Diversity commentary.',
            'vet_summary' => 'Vet summary.',
            'goal' => 'Goal.',
            'recommended_actions' => "Action one.\nAction two.",
            'score_gut_wall' => 'Low', 'score_skin_allergy' => 'Low', 'score_behaviour_mood' => 'Low',
            'score_gut_barrier' => 'Low', 'score_gas_digestive' => 'Low', 'score_stress_resilience' => 'Low',
        ], $proseOverrides);

        return [
            'interpretations' => $interp,
            'phylum_totals' => $phylumTotals,
            'diversity_score' => 2.4,
            'triggered' => ['AMR'],
            'plan_id' => 7,
            'generation_error' => null,
        ];
    }
}
