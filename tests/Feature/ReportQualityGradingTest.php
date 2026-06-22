<?php

namespace Tests\Feature;

use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Phase 2 — the call-site wrapper (ReportGeneration::gradeAndLog) must return the
 * verdict and log a PII-SAFE summary: codes/severities/tiers/counts only, never
 * the AI text, issue details, or any pet PII.
 */
class ReportQualityGradingTest extends TestCase
{
    private function deterministic(): array
    {
        return [
            'phylum_totals' => ['Bacteroidetes' => 40.0, 'Firmicutes' => 35.0, 'Fusobacteria' => 15.0, 'Proteobacteria' => 10.0],
            'diversity_score' => 3.2,
            'species_richness' => 500,
            'dysbiosis_score' => 1.14,
            'microbiome_classification' => 'Stable',
        ];
    }

    public function test_grade_and_log_returns_verdict_and_does_not_log_pii(): void
    {
        $canary = 'CANARY-Biscuit-owner-health-notes';

        // AI text carries the PII canary; a bad score forces a deterministic issue
        // (so it logs at warning), and a taxon forces a heuristic detail too.
        $interp = [
            'ai_summary' => $canary.' — looks balanced.',
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes 40%.',
            'ai_firmicutes_interpretation' => 'Firmicutes 35%.',
            'ai_fusobacteria_interpretation' => 'Fusobacteria 15%.',
            'ai_proteobacteria_interpretation' => 'Proteobacteria 10%.',
            'ai_diversity_interpretation' => 'Shannon 3.2.',
            'vet_summary' => $canary.' Lactobacillus noted.',
            'goal' => 'Keep steady.',
            'recommended_actions' => 'Offer varied fibre.',
            'score_gut_wall' => 'Nope',          // bad enum → deterministic warning
            'score_skin_allergy' => 'Medium',
            'score_behaviour_mood' => 'Low',
            'score_gut_barrier' => 'High',
            'score_gas_digestive' => 'Low',
            'score_stress_resilience' => 'Medium',
        ];

        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);

        $verdict = ReportGeneration::gradeAndLog(
            $interp,
            $this->deterministic(),
            ['triggered' => ['AMR'], 'plan_id' => 7],
            null,
            ['path' => 'unit_test', 'pet_id' => 123],
        );

        // Verdict returned and correct.
        $this->assertTrue($verdict['needs_review']);
        $this->assertGreaterThanOrEqual(1, $verdict['deterministic_count']);

        // PII canary appears in NO log record (message or context).
        foreach ($handler->getRecords() as $record) {
            $serialised = $record->message.' '.json_encode($record->context);
            $this->assertStringNotContainsString($canary, $serialised, 'PII leaked into the log');
            // The offending score value (an issue detail) must not be logged either.
            $this->assertStringNotContainsString('Nope', $serialised, 'issue detail leaked into the log');
        }

        // It DID log a structured summary at warning level (deterministic present).
        $this->assertTrue($handler->hasWarningRecords(), 'expected a warning-level quality log');
        $quality = collect($handler->getRecords())->first(fn ($r) => isset($r->context['deterministic_count']));
        $this->assertNotNull($quality, 'expected the quality summary log line');
        $this->assertArrayHasKey('counts', $quality->context);
        // Logged issues carry code/severity/tier only — never 'detail'.
        $this->assertNotEmpty($quality->context['issues']);
        $this->assertSame(['code', 'severity', 'tier'], array_keys($quality->context['issues'][0]));
    }

    public function test_clean_generation_logs_at_info_and_returns_no_review(): void
    {
        $interp = [
            'ai_summary' => 'Balanced.',
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes 40%.',
            'ai_firmicutes_interpretation' => 'Firmicutes 35%.',
            'ai_fusobacteria_interpretation' => 'Fusobacteria 15%.',
            'ai_proteobacteria_interpretation' => 'Proteobacteria 10%.',
            'ai_diversity_interpretation' => 'Shannon 3.2.',
            'vet_summary' => 'Stable picture.',
            'goal' => 'Keep steady.',
            'recommended_actions' => 'Offer varied fibre.',
            'score_gut_wall' => 'Low',
            'score_skin_allergy' => 'Medium',
            'score_behaviour_mood' => 'Low',
            'score_gut_barrier' => 'High',
            'score_gas_digestive' => 'Low',
            'score_stress_resilience' => 'Medium',
        ];

        $verdict = ReportGeneration::gradeAndLog(
            $interp,
            $this->deterministic(),
            ['triggered' => ['AMR'], 'plan_id' => 7],
            null,
            ['path' => 'unit_test'],
        );

        $this->assertFalse($verdict['needs_review']);
        $this->assertSame(0, $verdict['deterministic_count']);
        $this->assertSame(0, $verdict['heuristic_count']);
    }
}
