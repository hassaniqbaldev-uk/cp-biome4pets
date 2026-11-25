<?php

namespace Tests\Feature;

use App\Support\RetestPlanRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the FMT retest rule (step 1a), in isolation from the DB. The rule reads
 * the frozen comparison's overview figures — overview.{original,retest}.{diversity,
 * species_richness} — and fires only when BOTH: species richness fell by ≥50% of the
 * original AND diversity (Shannon) fell by ≥0.5 absolute. All guardrails return false.
 */
class RetestPlanRulesTest extends TestCase
{
    /** Build a comparison with the given overview figures. */
    private function comparison(mixed $origDiv, mixed $origRich, mixed $retDiv, mixed $retRich): array
    {
        return [
            'overview' => [
                'original' => ['diversity' => $origDiv, 'species_richness' => $origRich],
                'retest' => ['diversity' => $retDiv, 'species_richness' => $retRich],
            ],
        ];
    }

    public function test_fires_when_both_conditions_are_met(): void
    {
        // Teddy case: richness 463→215 (46.4% remain → 53.6% drop), diversity 3.16→2.02 (−1.14).
        $this->assertTrue(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.16, 463, 2.02, 215),
        ));
    }

    public function test_does_not_fire_when_only_diversity_drops_enough(): void
    {
        // Diversity drops 0.6 (≥0.5 ✓) but richness drops only 20% (<50% ✗).
        $this->assertFalse(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.16, 500, 2.56, 400),
        ));
    }

    public function test_does_not_fire_when_only_richness_drops_enough(): void
    {
        // Richness drops 60% (≥50% ✓) but diversity drops only 0.2 (<0.5 ✗).
        $this->assertFalse(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.16, 500, 2.96, 200),
        ));
    }

    public function test_does_not_fire_when_neither_condition_is_met(): void
    {
        // Tiny drops both ways.
        $this->assertFalse(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.16, 463, 3.10, 450),
        ));
    }

    public function test_fires_exactly_on_the_thresholds(): void
    {
        // Richness exactly 50% down (400→200), diversity exactly 0.5 down (3.0→2.5).
        $this->assertTrue(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.0, 400, 2.5, 200),
        ));
    }

    public function test_guardrail_original_richness_zero_does_not_fire_or_error(): void
    {
        $this->assertFalse(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.16, 0, 2.02, 0),
        ));
    }

    public function test_guardrail_missing_figures_do_not_fire(): void
    {
        // A null figure anywhere → false.
        $this->assertFalse(RetestPlanRules::fmtRetestFires(
            $this->comparison(3.16, 463, null, 215),
        ));
        $this->assertFalse(RetestPlanRules::fmtRetestFires(
            $this->comparison(null, 463, 2.02, 215),
        ));
    }

    public function test_guardrail_missing_overview_does_not_fire(): void
    {
        $this->assertFalse(RetestPlanRules::fmtRetestFires([]));
        $this->assertFalse(RetestPlanRules::fmtRetestFires(['overview' => ['original' => ['diversity' => 3.16]]]));
    }
}
