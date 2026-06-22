<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Support\ReportGeneration;
use App\Support\ReportQualityValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 — persistence + the deterministic-only review flag.
 */
class ReportReviewFlagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        // Guarantee no OpenAI key anywhere, so generation errors deterministically.
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    /** Persist a Report with the given attrs, supplying the required client_id. */
    private function report(array $attrs): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);

        return Report::create(array_merge(['client_id' => $client->id], $attrs));
    }

    private function test(): Test
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'KMS734', 'sample_id' => 'KMS734',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => []],
        ]);
    }

    // ───────────────────────── verdict → review_flags ─────────────────────────

    public function test_review_flags_from_verdict_wraps_issues_and_nulls_when_clean(): void
    {
        $this->assertNull(ReportGeneration::reviewFlagsFromVerdict([
            'issues' => [], 'needs_review' => false,
        ]));

        $verdict = [
            'issues' => [
                ['code' => 'bad_score_enum', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'x'],
            ],
            'needs_review' => true,
        ];
        $flags = ReportGeneration::reviewFlagsFromVerdict($verdict);

        $this->assertArrayHasKey('detected_at', $flags);
        $this->assertCount(1, $flags['issues']);
        $this->assertSame('bad_score_enum', $flags['issues'][0]['code']);
    }

    // ───────────────────────── persistence at generation ─────────────────────────

    public function test_create_report_from_test_persists_a_deterministic_flag(): void
    {
        // With no API key the AI call fails → generation_failed (deterministic).
        $report = ReportGeneration::createReportFromTest($this->test());

        $this->assertTrue($report->needs_review);
        $this->assertContains('generation_failed', array_column($report->reviewIssues(), 'code'));
        // review_flags persisted as an array with the wrapper shape.
        $this->assertArrayHasKey('detected_at', $report->fresh()->review_flags);
    }

    public function test_casts_and_issue_helpers_split_by_tier(): void
    {
        $report = $this->report([
            'status' => 'draft',
            'needs_review' => true,
            'review_flags' => ['detected_at' => '2026-06-21T00:00:00+00:00', 'issues' => [
                ['code' => 'bad_score_enum', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'score_gut_wall = (empty)'],
                ['code' => 'number_contradiction', 'severity' => 'info', 'tier' => 'heuristic', 'detail' => 'stated 55% vs computed 40%'],
            ]],
            'pet_snapshot' => ['name' => 'Biscuit'],
        ]);

        $fresh = $report->fresh();
        $this->assertIsBool($fresh->needs_review);
        $this->assertTrue($fresh->needs_review);
        $this->assertIsArray($fresh->review_flags);

        $this->assertCount(1, $fresh->deterministicReviewIssues());
        $this->assertSame('bad_score_enum', $fresh->deterministicReviewIssues()[0]['code']);
        $this->assertCount(1, $fresh->heuristicReviewIssues());
        $this->assertSame('number_contradiction', $fresh->heuristicReviewIssues()[0]['code']);
    }

    public function test_heuristic_only_verdict_does_not_set_needs_review_but_is_recorded(): void
    {
        // A faithful, valid generation EXCEPT one prose figure contradicts → the
        // only issue is heuristic, so needs_review must stay false.
        $interp = [
            'ai_summary' => 'Balanced.',
            'ai_bacteroidetes_interpretation' => 'Bacteroidetes is around 55%.', // computed 40 → heuristic flag
            'ai_firmicutes_interpretation' => 'Firmicutes 35%.',
            'ai_fusobacteria_interpretation' => 'Fusobacteria 15%.',
            'ai_proteobacteria_interpretation' => 'Proteobacteria 10%.',
            'ai_diversity_interpretation' => 'Shannon 3.2.',
            'vet_summary' => 'Stable.',
            'goal' => 'Keep steady.',
            'recommended_actions' => 'Offer varied fibre.',
            'score_gut_wall' => 'Low', 'score_skin_allergy' => 'Medium', 'score_behaviour_mood' => 'Low',
            'score_gut_barrier' => 'High', 'score_gas_digestive' => 'Low', 'score_stress_resilience' => 'Medium',
        ];
        $verdict = ReportQualityValidator::validate([
            'interpretations' => $interp,
            'phylum_totals' => ['Bacteroidetes' => 40.0, 'Firmicutes' => 35.0, 'Fusobacteria' => 15.0, 'Proteobacteria' => 10.0],
            'diversity_score' => 3.2,
            'triggered' => ['AMR'],
            'plan_id' => 7,
            'generation_error' => null,
        ]);

        $this->assertFalse($verdict['needs_review']);
        $this->assertSame(1, $verdict['heuristic_count']);

        $report = $this->report([
            'status' => 'draft',
            'needs_review' => $verdict['needs_review'],
            'review_flags' => ReportGeneration::reviewFlagsFromVerdict($verdict),
            'pet_snapshot' => ['name' => 'x'],
        ]);

        $this->assertFalse($report->fresh()->needs_review);          // not flagged
        $this->assertNotEmpty($report->fresh()->heuristicReviewIssues()); // but recorded
        $this->assertEmpty($report->fresh()->deterministicReviewIssues());
    }

    // ───────────────────────── nav badge count ─────────────────────────

    public function test_navigation_badge_counts_only_flagged_reports(): void
    {
        $this->assertNull(ReportResource::getNavigationBadge());

        $this->report(['status' => 'draft', 'needs_review' => true, 'pet_snapshot' => ['name' => 'a']]);
        $this->report(['status' => 'draft', 'needs_review' => true, 'pet_snapshot' => ['name' => 'b']]);
        $this->report(['status' => 'draft', 'needs_review' => false, 'pet_snapshot' => ['name' => 'c']]);

        $this->assertSame('2', ReportResource::getNavigationBadge());
        $this->assertSame('warning', ReportResource::getNavigationBadgeColor());
    }
}
