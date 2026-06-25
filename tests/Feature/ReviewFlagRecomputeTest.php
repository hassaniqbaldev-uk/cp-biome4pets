<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "needs review" flags must reflect CURRENT state, not the snapshot taken at
 * generation. The reported bug: the "no plan selected" nag persisted after an
 * admin had already chosen a plan. recomputeReviewState() re-derives the
 * edit-resolvable flags (plan selection + score enums) on save.
 */
class ReviewFlagRecomputeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private array $validScores = [
        'score_gut_wall' => 'Low', 'score_skin_allergy' => 'Medium', 'score_behaviour_mood' => 'Low',
        'score_gut_barrier' => 'High', 'score_gas_digestive' => 'Low', 'score_stress_resilience' => 'Medium',
    ];

    private function report(array $attrs): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Louie']);

        return Report::create(array_merge([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'draft',
            'pet_snapshot' => ['name' => 'Louie'],
        ], $this->validScores, $attrs));
    }

    private function planIssue(string $code): array
    {
        return ['code' => $code, 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'x'];
    }

    private function codes(Report $r): array
    {
        return array_column($r->fresh()->review_flags['issues'] ?? [], 'code');
    }

    // ── generation records the plan origin marker ────────────────────────────
    public function test_review_flags_record_the_plan_origin(): void
    {
        $flags = ReportGeneration::reviewFlagsFromVerdict([
            'issues' => [$this->planIssue('unwell_no_plan')], 'needs_review' => true,
        ]);
        $this->assertSame('unwell_no_plan', $flags['plan_origin']);

        // A non-plan verdict carries no plan_origin.
        $clean = ReportGeneration::reviewFlagsFromVerdict([
            'issues' => [['code' => 'band_contradiction', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'x']],
            'needs_review' => true,
        ]);
        $this->assertArrayNotHasKey('plan_origin', $clean);
    }

    // ── THE BUG: a plan is selected → the "choose a plan" nag is gone ─────────
    public function test_selecting_a_plan_clears_the_no_plan_nag_and_raises_manual_review(): void
    {
        $plan = Plan::create(['key' => 'p'.uniqid(), 'name' => 'Restore', 'enabled' => true]);
        $report = $this->report([
            'plan_id' => $plan->id,   // a plan IS selected now
            'needs_review' => true,
            'review_flags' => ['detected_at' => '2026-06-20T00:00:00+00:00', 'plan_origin' => 'unwell_no_plan',
                'issues' => [$this->planIssue('unwell_no_plan')]],
        ]);

        ReportGeneration::recomputeReviewState($report);

        $codes = $this->codes($report);
        $this->assertNotContains('unwell_no_plan', $codes);   // stale nag gone
        $this->assertContains('manual_plan_review', $codes);  // becomes the sanity check
        $this->assertTrue($report->fresh()->needs_review);    // still actionable (Super Admin)
    }

    // ── No plan at all → the original "choose a plan" flag is correct here ────
    public function test_no_plan_keeps_the_choose_a_plan_flag(): void
    {
        $report = $this->report([
            'plan_id' => null,
            'needs_review' => true,
            'review_flags' => ['detected_at' => '2026-06-20T00:00:00+00:00', 'plan_origin' => 'unwell_no_plan',
                'issues' => [$this->planIssue('unwell_no_plan')]],
        ]);

        ReportGeneration::recomputeReviewState($report);

        $codes = $this->codes($report);
        $this->assertContains('unwell_no_plan', $codes);
        $this->assertNotContains('manual_plan_review', $codes);
        $this->assertTrue($report->fresh()->needs_review);
    }

    // ── Auto-matched normally (no plan_origin) → never gets a plan flag ───────
    public function test_auto_matched_report_gets_no_plan_flag(): void
    {
        $plan = Plan::create(['key' => 'p'.uniqid(), 'name' => 'Restore', 'enabled' => true]);
        $report = $this->report(['plan_id' => $plan->id, 'needs_review' => false, 'review_flags' => null]);

        ReportGeneration::recomputeReviewState($report);

        $this->assertNull($report->fresh()->review_flags);
        $this->assertFalse($report->fresh()->needs_review);
    }

    // ── AUDIT: bad_score_enum also reflects current state ────────────────────
    public function test_correcting_a_bad_score_clears_its_flag(): void
    {
        $report = $this->report([
            'score_gut_wall' => 'Low',   // now valid
            'needs_review' => true,
            'review_flags' => ['detected_at' => '2026-06-20T00:00:00+00:00',
                'issues' => [['code' => 'bad_score_enum', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'score_gut_wall = Elevated']]],
        ]);

        ReportGeneration::recomputeReviewState($report);

        $this->assertNull($report->fresh()->review_flags);   // resolved
        $this->assertFalse($report->fresh()->needs_review);
    }

    public function test_introducing_a_bad_score_raises_the_flag(): void
    {
        $report = $this->report(['score_gas_digestive' => 'Elevated', 'needs_review' => false, 'review_flags' => null]);

        ReportGeneration::recomputeReviewState($report);

        $this->assertContains('bad_score_enum', $this->codes($report));
        $this->assertTrue($report->fresh()->needs_review);
    }

    // ── Preserves unrelated flags + respects "Mark as reviewed" ──────────────
    public function test_preserves_prose_flags_and_respects_prior_review(): void
    {
        $plan = Plan::create(['key' => 'p'.uniqid(), 'name' => 'Restore', 'enabled' => true]);
        // Already-manual report, acknowledged (needs_review false), plus a prose flag.
        $report = $this->report([
            'plan_id' => $plan->id,
            'needs_review' => false,   // was Marked as reviewed
            'review_flags' => ['detected_at' => '2026-06-20T00:00:00+00:00', 'plan_origin' => 'unwell_no_plan',
                'issues' => [$this->planIssue('manual_plan_review'),
                    ['code' => 'band_contradiction', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'x']]],
        ]);

        ReportGeneration::recomputeReviewState($report);

        $codes = $this->codes($report);
        $this->assertContains('manual_plan_review', $codes);   // unchanged plan family
        $this->assertContains('band_contradiction', $codes);   // prose flag preserved
        // Issue set unchanged → the prior "reviewed" stays; we don't resurrect it.
        $this->assertFalse($report->fresh()->needs_review);
    }
}
