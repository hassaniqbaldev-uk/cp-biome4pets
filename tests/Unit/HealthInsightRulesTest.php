<?php

namespace Tests\Unit;

use App\Support\HealthInsightRules;
use PHPUnit\Framework\TestCase;

/**
 * Stage 2: the deterministic health-insights rules engine. Each insight computes
 * the correct band from a driver percentage per the (assumption-flagged) config,
 * and attaches the client's EXACT comment text for that band. Thresholds all live
 * in HealthInsightRules::HEALTH_INSIGHT_RULES; these tests lock the CONFIRMED
 * behaviours and pin the current assumptions so a config change is visible.
 */
class HealthInsightRulesTest extends TestCase
{
    private function band(string $field, float $value): array
    {
        return HealthInsightRules::computeInsight($field, $value);
    }

    // 1. SKIN & ALLERGY — Bacteroidetes (High >30 / Target 25 / Low <20 CONFIRMED).
    public function test_skin_allergy_bacteroidetes_bands_and_comments(): void
    {
        $this->assertSame('High', $this->band('score_skin_allergy', 32)['label']);
        $this->assertStringStartsWith('Higher levels of Bacteroidetes', $this->band('score_skin_allergy', 32)['comment']);

        $this->assertSame('Target', $this->band('score_skin_allergy', 25)['label']);
        $this->assertSame('good', $this->band('score_skin_allergy', 25)['tone']); // target = green
        $this->assertStringStartsWith('Bacteroidetes are within the optimal range', $this->band('score_skin_allergy', 25)['comment']);

        $this->assertSame('Low', $this->band('score_skin_allergy', 18)['label']);
        $this->assertStringStartsWith('Low levels of Bacteroidetes', $this->band('score_skin_allergy', 18)['comment']);

        // Either side of target = Medium. CONFIRMED: carries the optimal/on-target
        // wording (reassuring), stays AMBER, and is no longer flagged as open.
        $medium = $this->band('score_skin_allergy', 27);
        $this->assertSame('Medium', $medium['label']);
        $this->assertFalse($medium['needs_client_comment']);
        $this->assertSame('warn', $medium['tone']); // amber, not green
        $this->assertStringStartsWith('Bacteroidetes are within the optimal range', $medium['comment']);
        $this->assertSame('Medium', $this->band('score_skin_allergy', 22)['label']);

        // Target is the favourable/green band here.
        $this->assertTrue($this->band('score_skin_allergy', 25)['favourable']);
        $this->assertFalse($this->band('score_skin_allergy', 32)['favourable']);
    }

    // 2. BEHAVIOUR & MOOD — Firmicutes (High >25 / Target 25 / Low <25).
    public function test_behaviour_mood_firmicutes_bands(): void
    {
        $this->assertSame('High', $this->band('score_behaviour_mood', 26)['label']);
        $this->assertSame('Target', $this->band('score_behaviour_mood', 25)['label']);
        $this->assertSame('Low', $this->band('score_behaviour_mood', 20)['label']);
        $this->assertStringStartsWith('Firmicutes are above the target range', $this->band('score_behaviour_mood', 26)['comment']);
    }

    // 3. METABOLIC — Verrucomicrobia CONFIRMED 3-band range: <1 Low / 1-4 Healthy
    //    Optimal (green) / >4 High, absent → Low, and NO tolerance mechanism.
    public function test_metabolic_verrucomicrobia_confirmed_range_bands(): void
    {
        // Low <1 (incl. absent) — exact short client comment.
        $low = $this->band('score_gut_barrier', 0.5);
        $this->assertSame('Low', $low['label']);
        $this->assertSame('Reduced metabolic support', $low['comment']);
        $this->assertSame('warn', $low['tone']);

        $absent = $this->band('score_gut_barrier', 0.0);
        $this->assertSame('Low', $absent['label']);

        // 1.0–4.0 inclusive → Healthy Optimal (favourable/green).
        foreach ([1.0, 2.5, 4.0] as $v) {
            $ho = $this->band('score_gut_barrier', $v);
            $this->assertSame('Healthy Optimal', $ho['label'], "value {$v} should be Healthy Optimal");
            $this->assertSame('Healthy metabolic function', $ho['comment']);
            $this->assertSame('good', $ho['tone']);
            $this->assertTrue($ho['favourable']);
        }

        // >4 → High.
        $high = $this->band('score_gut_barrier', 4.5);
        $this->assertSame('High', $high['label']);
        $this->assertSame('Metabolic stress/adaptation – investigate in context', $high['comment']);
        $this->assertSame('bad', $high['tone']);

        // No leftover Medium band and no needs_client_comment on this insight.
        $this->assertFalse($this->band('score_gut_barrier', 2.5)['needs_client_comment']);
    }

    public function test_metabolic_does_not_use_the_target_tolerance(): void
    {
        // 0.9 is within ±0.25 of the OLD 2.5-target's neighbours but must NOT be
        // pulled to any target band — it bands strictly by the explicit ranges.
        // Just under 1.0 is Low; exactly 1.0 flips to Healthy Optimal (range edge),
        // proving the boundary is the numeric edge, not a ± tolerance window.
        $this->assertSame('Low', $this->band('score_gut_barrier', 0.99)['label']);
        $this->assertSame('Healthy Optimal', $this->band('score_gut_barrier', 1.0)['label']);
    }

    // 4. GUT WALL — Blautia ≥3/2-3/<2 → 3/2/1; top band is now "Optimal Health".
    public function test_gut_wall_blautia_scores_3_2_1(): void
    {
        $target = $this->band('score_gut_wall', 3.5);
        $this->assertSame('Optimal Health', $target['label']);
        $this->assertSame(3, $target['level']);
        $this->assertTrue($target['favourable']);
        $this->assertStringStartsWith('Blautia levels are within the target range', $target['comment']);

        // ≥3 incl. higher all Optimal Health (score 3).
        $this->assertSame(3, $this->band('score_gut_wall', 5)['level']);
        $this->assertSame('Optimal Health', $this->band('score_gut_wall', 5)['label']);
        $this->assertSame(3, $this->band('score_gut_wall', 3)['level']);

        $disrupted = $this->band('score_gut_wall', 2.4);
        $this->assertSame('Disrupted', $disrupted['label']);
        $this->assertSame(2, $disrupted['level']);

        $leaky = $this->band('score_gut_wall', 1.2);
        $this->assertSame('Leaky Gut', $leaky['label']);
        $this->assertSame(1, $leaky['level']);
        $this->assertStringContainsString('leaky gut', $leaky['comment']);

        // Absent Blautia (0%) → Leaky Gut.
        $this->assertSame('Leaky Gut', $this->band('score_gut_wall', 0.0)['label']);
    }

    // 5. GAS — Escherichia/Shigella (High >0.5 / Target 0.5 / Low <0.5, low is GOOD).
    public function test_gas_escherichia_shigella_low_is_favourable(): void
    {
        $high = $this->band('score_gas_digestive', 0.8);
        $this->assertSame('High', $high['label']);
        $this->assertFalse($high['favourable']);

        $low = $this->band('score_gas_digestive', 0.2);
        $this->assertSame('Low', $low['label']);
        $this->assertTrue($low['favourable']);          // low is the green/favourable band
        $this->assertSame('good', $low['tone']);
        $this->assertStringStartsWith('Escherichia/Shigella levels are low', $low['comment']);

        // Exactly on 0.5 → Target (no tolerance window here).
        $this->assertSame('Target', $this->band('score_gas_digestive', 0.5)['label']);
    }

    // 6. STRESS RESILIENCE — Firmicutes (High >25.99 / Target ~25 / Low <25) + note.
    public function test_stress_resilience_bands_and_shared_note(): void
    {
        $this->assertSame('High', $this->band('score_stress_resilience', 26)['label']);
        $this->assertSame('Low', $this->band('score_stress_resilience', 24)['label']);

        // [25, 25.99] is Target (asymmetric, no tolerance).
        $this->assertSame('Target', $this->band('score_stress_resilience', 25.5)['label']);
        $this->assertSame('Target', $this->band('score_stress_resilience', 25.0)['label']);

        // The shared explanatory note appears on this insight.
        $note = $this->band('score_stress_resilience', 25.5)['shared_note'];
        $this->assertStringContainsString('Firmicutes play multiple roles', $note);
    }

    public function test_target_tolerance_is_a_single_adjustable_constant(): void
    {
        // 24.8% reads as Target (within ±TARGET_TOLERANCE of 25) rather than Medium.
        $this->assertSame('Target', $this->band('score_skin_allergy', 24.8)['label']);
        // Just outside the window falls to the adjacent band.
        $this->assertSame('Medium', $this->band('score_skin_allergy', 24.0)['label']);
        // The tolerance is one clearly-labelled constant (0 would make targets exact).
        $this->assertIsFloat((float) HealthInsightRules::TARGET_TOLERANCE);
    }

    public function test_compute_scores_maps_every_driver_to_a_band_label(): void
    {
        $scores = HealthInsightRules::computeScores([
            'Bacteroidetes' => 32,
            'Firmicutes' => 26,
            'Verrucomicrobia' => 0,        // absent → Low
            'Blautia' => 3.5,              // → Optimal Health
            'Escherichia/Shigella' => 0.1, // low = good
        ]);

        $this->assertSame([
            'score_skin_allergy' => 'High',
            'score_behaviour_mood' => 'High',
            'score_gut_barrier' => 'Low',
            'score_gut_wall' => 'Optimal Health',
            'score_gas_digestive' => 'Low',
            'score_stress_resilience' => 'High',
        ], $scores);
    }

    public function test_describe_by_label_follows_the_chosen_band(): void
    {
        // An override to 'Low' on the skin insight must carry the Low comment/tone.
        $d = HealthInsightRules::describeByLabel('score_skin_allergy', 'Low');
        $this->assertStringStartsWith('Low levels of Bacteroidetes', $d['comment']);

        // Unknown/empty label → safe descriptor with no comment.
        $blank = HealthInsightRules::describeByLabel('score_skin_allergy', null);
        $this->assertNull($blank['comment']);
        $this->assertNull($blank['label']);
    }
}
