<?php

namespace Tests\Unit;

use App\Support\ReportQualityValidator;
use PHPUnit\Framework\TestCase;

/**
 * The two new DETERMINISTIC quality flags (both drive needs_review):
 *  - unwell_no_plan: classification is unwell but no plan was selected (the
 *    safety net for the classification-gated router — makes the miss loud).
 *  - panel_contradiction: the report's own panels disagree — an "Imbalanced &
 *    Depleted" verdict while the diversity DISPLAY band reads "High".
 */
class UnwellAndContradictionFlagsTest extends TestCase
{
    /** A baseline context with valid AI output, so only the flags under test appear. */
    private function context(array $overrides = []): array
    {
        $interp = [
            'ai_summary' => 'A clear overall summary for the pet.',
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes commentary.',
            'ai_firmicutes_interpretation' => 'Firmicutes commentary.',
            'ai_fusobacteria_interpretation' => 'Fusobacteria commentary.',
            'ai_proteobacteria_interpretation' => 'Proteobacteria commentary.',
            'ai_diversity_interpretation' => 'Diversity commentary.',
            'vet_summary' => 'A warm personal summary.',
            'goal' => 'A concrete goal for the coming weeks.',
            'recommended_actions' => "Action one.\nAction two.",
            'score_gut_wall' => 'Low',
            'score_skin_allergy' => 'Low',
            'score_behaviour_mood' => 'Low',
            'score_gut_barrier' => 'Low',
            'score_gas_digestive' => 'Low',
            'score_stress_resilience' => 'Low',
        ];

        return array_merge([
            'interpretations' => $interp,
            'phylum_totals' => ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8],
            'diversity_score' => 2.89,
            'species_richness' => 267,
            'dysbiosis_score' => 1.66,
            'microbiome_classification' => 'Imbalanced & Depleted',
            'triggered' => [],
            'plan_id' => null,
            'generation_error' => null,
        ], $overrides);
    }

    private function codes(array $verdict): array
    {
        return array_column($verdict['issues'], 'code');
    }

    public function test_unwell_with_no_plan_flags_unwell_no_plan_and_needs_review(): void
    {
        $v = ReportQualityValidator::validate($this->context());

        $this->assertContains('unwell_no_plan', $this->codes($v));
        $this->assertTrue($v['needs_review']);

        $flag = collect($v['issues'])->firstWhere('code', 'unwell_no_plan');
        $this->assertSame('deterministic', $flag['tier']);
        $this->assertSame('warning', $flag['severity']);
        $this->assertStringContainsString('Imbalanced & Depleted', $flag['detail']);
    }

    public function test_stable_with_no_plan_does_not_flag_unwell_no_plan(): void
    {
        $v = ReportQualityValidator::validate($this->context([
            'microbiome_classification' => 'Stable',
            'diversity_score' => 3.2,   // also out of the contradiction case
        ]));

        $this->assertNotContains('unwell_no_plan', $this->codes($v));
    }

    public function test_depleted_with_high_diversity_band_flags_panel_contradiction(): void
    {
        // diversity 2.89 → "High" band (>2.5) while classification is depleted.
        $v = ReportQualityValidator::validate($this->context(['plan_id' => 7]));

        $this->assertContains('panel_contradiction', $this->codes($v));
        $this->assertTrue($v['needs_review']);

        $flag = collect($v['issues'])->firstWhere('code', 'panel_contradiction');
        $this->assertSame('deterministic', $flag['tier']);
        $this->assertSame('warning', $flag['severity']);
    }

    public function test_depleted_with_low_diversity_band_does_not_contradict(): void
    {
        // diversity 1.5 → "Low" band — consistent with a depleted verdict.
        $v = ReportQualityValidator::validate($this->context([
            'plan_id' => 7,
            'diversity_score' => 1.5,
        ]));

        $this->assertNotContains('panel_contradiction', $this->codes($v));
    }
}
