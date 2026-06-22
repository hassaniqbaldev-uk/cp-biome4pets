<?php

namespace Tests\Unit;

use App\Support\ReportQualityValidator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — the pure grader. Deterministic checks must fire with zero false
 * positives and set needs_review; heuristic checks must be precise and must
 * NEVER set needs_review (log-only this phase).
 */
class ReportQualityValidatorTest extends TestCase
{
    /** A clean, faithful generation: scores valid, numbers match, no taxa, no banned words. */
    private function cleanInterp(): array
    {
        return [
            'ai_summary' => "This pet's gut looks broadly balanced.",
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes sits at 40% which is within the healthy range.',
            'ai_firmicutes_interpretation' => 'Firmicutes is 35%, a healthy level.',
            'ai_fusobacteria_interpretation' => 'Fusobacteria is 15%.',
            'ai_proteobacteria_interpretation' => 'Proteobacteria is 10%.',
            'ai_diversity_interpretation' => 'The Shannon score of 3.2 is healthy for this pet.',
            'vet_summary' => 'Overall a stable picture for this pet.',
            'goal' => 'Keep things steady over the coming weeks.',
            'recommended_actions' => 'Offer a varied fibre intake to support balance.',
            'score_gut_wall' => 'Low',
            'score_skin_allergy' => 'Medium',
            'score_behaviour_mood' => 'Low',
            'score_gut_barrier' => 'High',
            'score_gas_digestive' => 'Low',
            'score_stress_resilience' => 'Very High',
        ];
    }

    private function ground(array $overrides = []): array
    {
        return array_merge([
            'interpretations' => $this->cleanInterp(),
            'phylum_totals' => ['Bacteroidetes' => 40.0, 'Firmicutes' => 35.0, 'Fusobacteria' => 15.0, 'Proteobacteria' => 10.0],
            'diversity_score' => 3.2,
            'species_richness' => 500,
            'dysbiosis_score' => 1.14,
            'microbiome_classification' => 'Stable',
            'species' => [],
            'triggered' => ['AMR'],
            'plan_id' => 7,
            'generation_error' => null,
        ], $overrides);
    }

    private function codes(array $verdict): array
    {
        return array_map(fn ($i) => $i['code'], $verdict['issues']);
    }

    // ───────────────────────── structure ─────────────────────────

    public function test_verdict_has_the_expected_structure(): void
    {
        $v = ReportQualityValidator::validate($this->ground());

        $this->assertArrayHasKey('issues', $v);
        $this->assertArrayHasKey('counts', $v);
        $this->assertSame(['error', 'warning', 'info'], array_keys($v['counts']));
        $this->assertArrayHasKey('deterministic_count', $v);
        $this->assertArrayHasKey('heuristic_count', $v);
        $this->assertArrayHasKey('needs_review', $v);
    }

    // ───────────────────────── deterministic ─────────────────────────

    public function test_clean_output_produces_no_issues(): void
    {
        $v = ReportQualityValidator::validate($this->ground());

        $this->assertSame([], $v['issues'], 'clean output should be issue-free');
        $this->assertSame(0, $v['deterministic_count']);
        $this->assertSame(0, $v['heuristic_count']);
        $this->assertFalse($v['needs_review']);
    }

    public function test_bad_score_enum_is_flagged_deterministically(): void
    {
        $interp = $this->cleanInterp();
        $interp['score_gut_wall'] = 'Elevated';   // not in the allowed set
        $interp['score_skin_allergy'] = '';       // empty is also invalid

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));

        $enum = array_values(array_filter($v['issues'], fn ($i) => $i['code'] === 'bad_score_enum'));
        $this->assertCount(2, $enum);
        $this->assertSame(ReportQualityValidator::TIER_DETERMINISTIC, $enum[0]['tier']);
        $this->assertSame(ReportQualityValidator::SEVERITY_WARNING, $enum[0]['severity']);
        $this->assertTrue($v['needs_review']);
    }

    public function test_empty_output_is_flagged(): void
    {
        $empty = array_fill_keys(
            array_merge(ReportQualityValidator::TEXT_FIELDS, ReportQualityValidator::SCORE_FIELDS),
            '',
        );

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $empty]));

        $this->assertContains('empty_output', $this->codes($v));
        // No per-score enum spam on a total blank.
        $this->assertNotContains('bad_score_enum', $this->codes($v));
        $this->assertTrue($v['needs_review']);
    }

    public function test_generation_error_is_surfaced_and_supersedes_empty_output(): void
    {
        $empty = array_fill_keys(
            array_merge(ReportQualityValidator::TEXT_FIELDS, ReportQualityValidator::SCORE_FIELDS),
            '',
        );

        $api = ReportQualityValidator::validate($this->ground(['interpretations' => $empty, 'generation_error' => 'api_failed']));
        $this->assertContains('generation_failed', $this->codes($api));
        $this->assertNotContains('empty_output', $this->codes($api), 'specific error supersedes the generic empty signal');
        $this->assertTrue($api['needs_review']);

        $json = ReportQualityValidator::validate($this->ground(['interpretations' => $empty, 'generation_error' => 'json_parse_failed']));
        $this->assertContains('json_parse_failed', $this->codes($json));
    }

    public function test_plan_unmatched_is_flagged_when_triggers_fire_but_no_plan(): void
    {
        $v = ReportQualityValidator::validate($this->ground(['triggered' => ['AMR', 'FMT'], 'plan_id' => null]));

        $this->assertContains('plan_unmatched', $this->codes($v));
        $this->assertTrue($v['needs_review']);
    }

    public function test_no_plan_unmatched_when_no_triggers_fire(): void
    {
        $v = ReportQualityValidator::validate($this->ground(['triggered' => [], 'plan_id' => null]));

        $this->assertNotContains('plan_unmatched', $this->codes($v));
        $this->assertFalse($v['needs_review']);
    }

    // ───────────────────────── heuristic (log-only) ─────────────────────────

    public function test_number_contradiction_flags_a_clear_mismatch_but_not_a_faithful_restatement(): void
    {
        // Faithful restatement (exact) → no contradiction.
        $clean = ReportQualityValidator::validate($this->ground());
        $this->assertNotContains('number_contradiction', $this->codes($clean));

        // Clear contradiction: prose says 55%, computed is 40%.
        $interp = $this->cleanInterp();
        $interp['ai_bacteroidetes_interpretation'] = 'Bacteroidetes is around 55% which looks high.';
        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));

        $contradiction = array_values(array_filter($v['issues'], fn ($i) => $i['code'] === 'number_contradiction'));
        $this->assertCount(1, $contradiction);
        $this->assertSame(ReportQualityValidator::TIER_HEURISTIC, $contradiction[0]['tier']);
        $this->assertSame(ReportQualityValidator::SEVERITY_INFO, $contradiction[0]['severity']);

        // CRITICAL: heuristic issues never set needs_review.
        $this->assertFalse($v['needs_review']);
        $this->assertSame(0, $v['deterministic_count']);
        $this->assertSame(1, $v['heuristic_count']);
    }

    public function test_number_contradiction_tolerates_rounding(): void
    {
        $interp = $this->cleanInterp();
        // Computed 40.0, prose says 41% — within the 2.0pp tolerance.
        $interp['ai_bacteroidetes_interpretation'] = 'Bacteroidetes is about 41%.';

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));
        $this->assertNotContains('number_contradiction', $this->codes($v));
    }

    public function test_diversity_contradiction_is_flagged(): void
    {
        $interp = $this->cleanInterp();
        // Computed 3.2, prose claims 1.1 — well beyond 0.3.
        $interp['ai_diversity_interpretation'] = 'The Shannon score of 1.1 is quite low.';

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));
        $this->assertContains('number_contradiction', $this->codes($v));
        $this->assertFalse($v['needs_review']);
    }

    public function test_unknown_taxon_is_flagged_as_heuristic_only(): void
    {
        $interp = $this->cleanInterp();
        $interp['recommended_actions'] = 'Consider a course that addresses Lactobacillus levels.';

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));

        $this->assertContains('unknown_taxon', $this->codes($v));
        $this->assertFalse($v['needs_review']);
        $this->assertSame(0, $v['deterministic_count']);
    }

    public function test_known_phylum_in_prose_is_not_flagged_as_unknown_taxon(): void
    {
        $interp = $this->cleanInterp();
        // Proteobacteria IS in the ground truth → must not be flagged.
        $interp['vet_summary'] = 'Proteobacteria is the main driver here for this pet.';

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));
        $this->assertNotContains('unknown_taxon', $this->codes($v));
    }

    public function test_banned_phrase_is_flagged_as_heuristic_only(): void
    {
        $interp = $this->cleanInterp();
        $interp['vet_summary'] = 'This will cure the condition and is a definitive diagnosis.';

        $v = ReportQualityValidator::validate($this->ground(['interpretations' => $interp]));

        $this->assertContains('banned_phrase', $this->codes($v));
        $this->assertFalse($v['needs_review']);
    }
}
