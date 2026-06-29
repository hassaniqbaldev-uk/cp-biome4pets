<?php

namespace Tests\Feature;

use App\Filament\Pages\BulkOperations;
use App\Models\BulkOperationRun;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Support\ReportGeneration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bulk Regenerate Reports: Super-Admin-only. INCLUSIVE table selection — filter
 * the table, TICK reports to include (or select-all), run the bulk action on the
 * SELECTED reports. Chunked in-portal processing (no queue/cron); one failure
 * never aborts; partial completion is safe.
 */
class BulkRegenerateReportsTest extends TestCase
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
        // No OpenAI key → generation reports api_failed (lets us assert the
        // "don't overwrite on failure" + "continue past failures" paths).
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@e.com',
            'role' => $role, 'password' => Hash::make('secret'),
        ]);
    }

    private function makeReport(string $reportDate, string $status = 'published', bool $needsReview = false, array $phylum = ['Fusobacteria' => 2.97]): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => $reportDate, 'phylum_data' => $phylum, 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => $phylum],
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => $status, 'needs_review' => $needsReview, 'pet_snapshot' => ['name' => 'Biscuit'],
            'ai_summary' => 'Existing summary that must survive a failed regen.',
        ]);
    }

    // ── Access (one role per test — switching users mid-test trips AuthenticateSession) ──
    public function test_admin_is_forbidden_at_the_url_level(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $this->get(BulkOperations::getUrl())->assertForbidden();
        $this->assertFalse(BulkOperations::canAccess());
        $this->assertFalse(BulkOperations::shouldRegisterNavigation());
    }

    public function test_super_admin_can_access(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->get(BulkOperations::getUrl())->assertOk();
        $this->assertTrue(BulkOperations::canAccess());
    }

    // ── The filter narrows the selectable table ─────────────────────────────
    public function test_filters_narrow_the_selectable_table(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $inRange = $this->makeReport('2026-03-10', 'published');
        $alsoIn = $this->makeReport('2026-03-20', 'published', needsReview: true);
        $tooEarly = $this->makeReport('2026-01-01', 'published');
        $tooLate = $this->makeReport('2026-06-01', 'published');
        $draft = $this->makeReport('2026-03-15', 'draft');

        // Date range → only the March rows, not Jan / June.
        Livewire::test(BulkOperations::class)
            ->filterTable('date_range', ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertCanSeeTableRecords([$inRange, $alsoIn, $draft])
            ->assertCanNotSeeTableRecords([$tooEarly, $tooLate]);

        // Status filter → published only.
        Livewire::test(BulkOperations::class)
            ->filterTable('status', 'published')
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$draft]);

        // Needs-review filter → only the flagged one.
        Livewire::test(BulkOperations::class)
            ->filterTable('needs_review', true)
            ->assertCanSeeTableRecords([$alsoIn])
            ->assertCanNotSeeTableRecords([$inRange, $draft]);
    }

    // ── Ticking INCLUDES: the run is the SELECTED reports ───────────────────
    public function test_selecting_reports_includes_them_in_the_run(): void
    {
        Bus::fake();
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $a = $this->makeReport('2026-04-10');
        $b = $this->makeReport('2026-04-11');
        $c = $this->makeReport('2026-04-12');

        // Tick A and C (NOT B) → run on exactly A and C.
        Livewire::test(BulkOperations::class)
            ->callTableBulkAction('regenerate', [$a->id, $c->id])
            ->assertSet('running', true)
            ->assertSet('total', 2)
            ->assertSet('pendingIds', fn (array $ids): bool => in_array($a->id, $ids)
                && in_array($c->id, $ids) && ! in_array($b->id, $ids));

        // Persisted run's batch is exactly the selected ids (order-agnostic).
        $run = BulkOperationRun::firstOrFail();
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $run->batch_ids);
        $this->assertSame(2, $run->total);

        // In-portal execution — nothing dispatched to any queue.
        Bus::assertNothingBatched();
        Bus::assertNothingDispatched();
    }

    public function test_select_all_filtered_then_run_covers_the_whole_filtered_set(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $marchA = $this->makeReport('2026-08-05');
        $marchB = $this->makeReport('2026-08-06');
        $other = $this->makeReport('2026-02-01');

        // Select the filtered set (the August two) and run.
        Livewire::test(BulkOperations::class)
            ->filterTable('date_range', ['from' => '2026-08-01', 'to' => '2026-08-31'])
            ->callTableBulkAction('regenerate', [$marchA->id, $marchB->id])
            ->assertSet('total', 2)
            ->assertSet('pendingIds', fn (array $ids): bool => in_array($marchA->id, $ids)
                && in_array($marchB->id, $ids) && ! in_array($other->id, $ids));
    }

    // ── Zero selected → no run (the bulk action can't regenerate zero) ──────
    public function test_zero_selection_starts_no_run(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $this->makeReport('2026-05-01');

        Livewire::test(BulkOperations::class)
            ->call('startRun', [])          // the bulk action with an empty selection
            ->assertSet('running', false)
            ->assertSet('total', 0);

        $this->assertSame(0, BulkOperationRun::count());
    }

    // ── Chunked processing: up to CHUNK_SIZE per chunk, auto-continue to done ──
    public function test_chunks_process_up_to_three_per_call_and_finish(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeReport('2026-05-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT))->id;
        }
        $this->assertSame(3, BulkOperations::CHUNK_SIZE);

        $page = Livewire::test(BulkOperations::class)
            ->callTableBulkAction('regenerate', $ids)
            ->assertSet('total', 5);

        $page->call('processChunk')
            ->assertSet('running', true)
            ->assertSet('pendingIds', fn (array $left): bool => count($left) === 2)
            ->assertSet('failed', 3);  // all api_failed (no key) — counted, not aborted

        $page->call('processChunk')
            ->assertSet('running', false)
            ->assertSet('finished', true)
            ->assertSet('pendingIds', [])
            ->assertSet('failed', 5)
            ->assertSet('succeeded', 0);

        // The view auto-continues via a poll while running.
        Livewire::test(BulkOperations::class)
            ->set('running', true)->set('total', 5)
            ->assertSee('wire:poll.800ms="processChunk"', false);
    }

    // ── Resilience / safety ─────────────────────────────────────────────────
    public function test_one_failure_does_not_abort_and_content_is_preserved(): void
    {
        $report = $this->makeReport('2026-05-15');
        $original = $report->ai_summary;

        $result = ReportGeneration::regenerateReport($report->fresh());

        $this->assertFalse($result['ok']);
        $this->assertSame('api_failed', $result['reason']);          // went through the real path
        $this->assertSame($original, $report->fresh()->ai_summary);  // NOT wiped on failure
    }

    public function test_partial_completion_is_safe_chunks_commit_incrementally(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->makeReport('2026-07-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT))->id;
        }

        $page = Livewire::test(BulkOperations::class)
            ->callTableBulkAction('regenerate', $ids);

        $page->call('processChunk')
            ->assertSet('running', true)
            ->assertSet('finished', false);
        $this->assertSame(3, $page->get('failed') + $page->get('succeeded'));
    }
}
