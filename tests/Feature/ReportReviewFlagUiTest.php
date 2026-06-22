<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 — the admin surfaces: "Mark as reviewed" clears the flag, the list
 * filter narrows to flagged reports, and a flagged report still publishes (the
 * flag is advisory, never a publish guard).
 */
class ReportReviewFlagUiTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $this->user = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'super_admin', 'password' => bcrypt('secret'),
        ]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private User $user;

    private function makeReport(bool $needsReview, string $status = 'draft'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'KMS'.uniqid(), 'sample_id' => 'KMS734',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => $status,
            'pet_snapshot' => ['name' => 'Biscuit'],
            'needs_review' => $needsReview,
            'review_flags' => $needsReview ? ['detected_at' => '2026-06-21T00:00:00+00:00', 'issues' => [
                ['code' => 'bad_score_enum', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'score_gut_wall = (empty)'],
            ]] : null,
        ]);
        $report->steps()->create(['title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    public function test_mark_as_reviewed_clears_the_flag_and_stamps_the_reviewer(): void
    {
        $report = $this->makeReport(needsReview: true);

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('mark_reviewed');

        $fresh = $report->fresh();
        $this->assertFalse($fresh->needs_review);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame($this->user->id, $fresh->reviewed_by);
        // review_flags kept for the audit trail.
        $this->assertNotNull($fresh->review_flags);
    }

    public function test_flagged_report_still_publishes_no_block(): void
    {
        $report = $this->makeReport(needsReview: true, status: 'draft');

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('publish');

        $fresh = $report->fresh();
        $this->assertSame('published', $fresh->status);
        // Publishing does not touch the advisory flag.
        $this->assertTrue($fresh->needs_review);
    }

    public function test_list_filter_narrows_to_flagged_reports(): void
    {
        $flagged = $this->makeReport(needsReview: true);
        $clean = $this->makeReport(needsReview: false);

        Livewire::test(ListReports::class)
            ->filterTable('needs_review', '1')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$clean]);
    }
}
