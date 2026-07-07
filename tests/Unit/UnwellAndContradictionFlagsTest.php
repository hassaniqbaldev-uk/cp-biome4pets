<?php

namespace Tests\Unit;

use App\Support\ReportQualityValidator;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic quality flags around classification:
 *  - unwell_no_plan: classification is unwell but no plan was selected (the
 *    safety net for the classification-gated router — makes the miss loud). Still
 *    active and drives needs_review.
 *  - panel_contradiction: RETIRED. It fired on "Imbalanced & Depleted" + a High
 *    diversity band, but an audit proved that is a false positive by construction
 *    (the Depleted verdict there always comes from LOW RICHNESS, not diversity —
 *    two different, co-existing metrics). It no longer fires and no longer drives
 *    needs_review; the mismatch is reconciled in the report copy instead.
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
            // All four named phyla present so the prose naming them is whitelisted
            // (the unknown_taxon guardrail otherwise fires on "Proteobacteria").
            'phylum_totals' => ['Fusobacteria' => 54.4, 'Firmicutes' => 26.2, 'Bacteroidetes' => 15.8, 'Proteobacteria' => 9.0],
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

    public function test_depleted_with_high_diversity_band_no_longer_flags_panel_contradiction(): void
    {
        // The retired false positive: diversity 2.89 → "High" band while the
        // classification is depleted (via low richness 267). This is a valid
        // biological state (high evenness, low species count), NOT a contradiction —
        // it must no longer be flagged. With a plan selected (so unwell_no_plan is
        // not in play) and valid output, the report has NO deterministic issue and
        // does NOT need review.
        $v = ReportQualityValidator::validate($this->context(['plan_id' => 7]));

        $this->assertNotContains('panel_contradiction', $this->codes($v));
        $this->assertFalse($v['needs_review']);
        $this->assertSame(0, $v['deterministic_count']);
    }

    public function test_depleted_with_low_diversity_band_also_does_not_flag(): void
    {
        // diversity 1.5 → "Low" band. Never flagged (the check is gone entirely).
        $v = ReportQualityValidator::validate($this->context([
            'plan_id' => 7,
            'diversity_score' => 1.5,
        ]));

        $this->assertNotContains('panel_contradiction', $this->codes($v));
        $this->assertFalse($v['needs_review']);
    }
}
