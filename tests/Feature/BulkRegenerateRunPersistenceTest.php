<?php

namespace Tests\Feature;

use App\Filament\Pages\BulkOperations;
use App\Filament\Widgets\BulkRegenerateRunCard;
use App\Models\BulkOperationRun;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Persisted bulk-regeneration runs: each run is written to the DB and updated per
 * chunk (so a closed tab is recoverable), stale runs are detected as interrupted,
 * the dashboard card surfaces completed/interrupted runs (Super-Admin only), and
 * Resume continues only the remaining reports.
 */
class BulkRegenerateRunPersistenceTest extends TestCase
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

    private function makeReport(string $date = '2026-05-10'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => $date, 'phylum_data' => ['Fusobacteria' => 2.97], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => ['Fusobacteria' => 2.97]],
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'], 'ai_summary' => 'Existing.',
        ]);
    }

    // ── Persistence: a run is created and updated each chunk ──────────────────
    public function test_run_persists_and_updates_each_chunk_to_completion(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeReport('2026-05-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT))->id;
        }

        // Select the reports and run via the inclusive bulk action.
        $page = Livewire::test(BulkOperations::class)
            ->callTableBulkAction('regenerate', $ids);

        // A run row was created (running, full batch remaining).
        $run = BulkOperationRun::firstOrFail();
        $this->assertSame('running', $run->status);
        $this->assertSame(5, $run->total);
        $this->assertCount(5, $run->remaining_ids);
        $this->assertNotNull($run->last_progress_at);

        // First chunk → row shows 3 done (remaining 2), counts bumped.
        $page->call('processChunk');
        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertCount(2, $run->remaining_ids);
        $this->assertSame(3, $run->failed_count);  // no API key → soft-failed, not aborted

        // Second chunk → completed.
        $page->call('processChunk');
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame([], $run->remaining_ids);
        $this->assertSame(5, $run->failed_count);
        $this->assertNotNull($run->finished_at);
    }

    // ── Heartbeat: a stale running run is detected as interrupted ─────────────
    public function test_stale_running_run_is_detected_and_materialised_as_interrupted(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $run = BulkOperationRun::create([
            'started_by' => $admin->id, 'total' => 10, 'batch_ids' => range(1, 10),
            'remaining_ids' => [7, 8, 9, 10], 'regenerated_count' => 5, 'failed_count' => 1,
            'status' => 'running', 'started_at' => now()->subMinutes(10),
            'last_progress_at' => Carbon::now()->subMinutes(5),   // stale
        ]);

        $this->assertTrue($run->isStale());
        $this->assertSame(6, $run->doneCount());      // 10 − 4 remaining
        $this->assertSame(4, $run->remainingCount());

        // dashboardCardFor materialises it to interrupted.
        $card = BulkOperationRun::dashboardCardFor($admin->id);
        $this->assertNotNull($card);
        $this->assertSame('interrupted', $card->status);
        $this->assertSame('interrupted', $run->fresh()->status);
    }

    public function test_fresh_running_run_is_not_flagged_and_shows_no_card(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $run = BulkOperationRun::create([
            'started_by' => $admin->id, 'total' => 5, 'batch_ids' => range(1, 5), 'remaining_ids' => [4, 5],
            'status' => 'running', 'started_at' => now(), 'last_progress_at' => now(),
        ]);

        $this->assertFalse($run->isStale());
        $this->assertNull(BulkOperationRun::dashboardCardFor($admin->id)); // live in a tab → no card
    }

    // ── Dashboard card: completed run + dismiss ──────────────────────────────
    public function test_completed_run_shows_card_with_stats_and_dismiss_clears_it(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($admin);

        $run = BulkOperationRun::create([
            'started_by' => $admin->id, 'total' => 8, 'batch_ids' => range(1, 8), 'remaining_ids' => [],
            'regenerated_count' => 6, 'failed_count' => 2, 'needs_review_count' => 3,
            'status' => 'completed', 'finished_at' => now(), 'last_progress_at' => now(),
        ]);

        $widget = Livewire::test(BulkRegenerateRunCard::class)
            ->assertSee('Bulk regeneration completed')
            ->assertSee('6')   // regenerated
            ->assertSee('Review 3');

        // Dismiss → acknowledged → card gone.
        $widget->call('acknowledge');
        $this->assertNotNull($run->fresh()->acknowledged_at);
        $this->assertNull(BulkOperationRun::dashboardCardFor($admin->id));
    }

    // ── Dashboard card: interrupted run + cancel ─────────────────────────────
    public function test_interrupted_card_can_be_cancelled(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($admin);

        BulkOperationRun::create([
            'started_by' => $admin->id, 'total' => 4, 'batch_ids' => range(1, 4), 'remaining_ids' => [3, 4],
            'regenerated_count' => 2, 'status' => 'running',
            'started_at' => now()->subMinutes(10), 'last_progress_at' => now()->subMinutes(5),
        ]);

        Livewire::test(BulkRegenerateRunCard::class)
            ->assertSee('A bulk regeneration was interrupted')
            ->assertSee('2')   // done of 4
            ->call('cancel');

        $this->assertSame('cancelled', BulkOperationRun::firstOrFail()->status);
        $this->assertNull(BulkOperationRun::dashboardCardFor($admin->id));
    }

    // ── Access: only Super Admins see the card ───────────────────────────────
    public function test_only_super_admin_sees_the_card(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $this->assertFalse(BulkRegenerateRunCard::canView());

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $this->assertTrue(BulkRegenerateRunCard::canView());
    }

    // ── Resume: continue only the remaining, not the done ────────────────────
    public function test_resume_continues_only_remaining_reports(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($admin);

        // 5 reports; pretend 3 already done, 2 remaining (interrupted).
        $reports = collect(range(1, 5))->map(fn () => $this->makeReport());
        $remaining = [$reports[3]->id, $reports[4]->id];

        $run = BulkOperationRun::create([
            'started_by' => $admin->id, 'total' => 5, 'batch_ids' => $reports->pluck('id')->all(),
            'remaining_ids' => $remaining, 'regenerated_count' => 2, 'failed_count' => 1,
            'status' => 'interrupted', 'started_at' => now()->subMinutes(10), 'last_progress_at' => now()->subMinutes(5),
        ]);

        // Resume via the dashboard's ?resume={id} link.
        $page = Livewire::withQueryParams(['resume' => $run->id])
            ->test(BulkOperations::class)
            ->assertSet('running', true)
            ->assertSet('total', 5)
            ->assertSet('succeeded', 2)   // cumulative from the persisted run
            ->assertSet('failed', 1)
            ->assertSet('pendingIds', fn (array $ids): bool => $ids === $remaining); // ONLY the remaining

        // One chunk finishes the 2 remaining; the 3 done are never reprocessed.
        $page->call('processChunk')->assertSet('running', false)->assertSet('finished', true);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame([], $run->remaining_ids);
        $this->assertSame(3, $run->failed_count); // 1 prior + 2 newly attempted (= only the remaining)
        $this->assertSame(2, $run->regenerated_count); // unchanged (the 3 done weren't redone)
    }
}
