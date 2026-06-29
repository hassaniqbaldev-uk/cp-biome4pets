<?php

namespace Tests\Feature;

use App\Filament\Pages\BulkOperations;
use App\Models\BulkOperationRun;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bulk SEND (unsent) — the first operation that emails real customers. Sends only
 * eligible reports (published, unsent, has-email) through the SHARED ReportSender;
 * ineligible selected reports are skipped + counted, never sent; the per-run cap
 * blocks an over-limit run; a 429 is left retriable; and — critically — a RESUMED
 * run never re-sends a report already sent in that run.
 */
class BulkSendTest extends TestCase
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
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
        $this->actingAs($this->superAdmin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'S', 'email' => 's'.uniqid().'@e.com',
            'role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make('x'),
        ]);
    }

    private function makeReport(string $status = 'published', string $email = 'owner@example.test'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => $email]);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-05-01', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => $status, 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
    }

    /** Drive a whole run to completion (poll until not running). */
    private function drain($page): void
    {
        for ($i = 0; $i < 50 && $page->get('running'); $i++) {
            $page->call('processChunk');
        }
    }

    // ── Only eligible reports are sent; the rest skipped + counted ────────────
    public function test_sends_only_eligible_and_skips_the_rest(): void
    {
        Http::fake(['*/api/events/' => Http::response([], 202)]);

        $eligible = $this->makeReport();                       // published, unsent, email
        $unpublished = $this->makeReport(status: 'draft');     // → skipped
        $noEmail = $this->makeReport(email: '');               // → skipped
        $alreadySent = $this->makeReport();
        $alreadySent->recordKlaviyoSend(true, 'prior');        // → skipped (already sent)

        $page = Livewire::test(BulkOperations::class)
            ->callTableBulkAction('send_unsent', [
                $eligible->id, $unpublished->id, $noEmail->id, $alreadySent->id,
            ], ['channel' => 'klaviyo'])
            ->assertSet('operation', 'send')
            ->assertSet('channel', 'klaviyo')
            ->assertSet('total', 4);

        $this->drain($page);

        $this->assertSame(1, $page->get('succeeded'));   // only the eligible one
        $this->assertSame(3, $page->get('skipped'));     // the other three
        $this->assertSame(0, $page->get('failed'));

        $this->assertTrue($eligible->fresh()->hasBeenSent());
        $this->assertFalse($unpublished->fresh()->hasBeenSent());
        $this->assertFalse($noEmail->fresh()->hasBeenSent());
        // Exactly one event went out.
        Http::assertSentCount(1);
    }

    // ── Per-run cap blocks an over-limit run ─────────────────────────────────
    public function test_per_run_cap_blocks_an_over_limit_send(): void
    {
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        $this->assertSame(200, BulkOperations::MAX_BULK_SEND);

        // MAX + 1 eligible reports (share one client/pet/test; each report just needs
        // a test_id + published + an email via the shared client).
        $client = Client::create(['name' => 'Owner', 'email' => 'bulk@example.test']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'OCAP', 'sample_id' => 'SCAP',
            'report_date' => '2026-05-01', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $ids = [];
        for ($i = 0; $i <= BulkOperations::MAX_BULK_SEND; $i++) {   // 201 reports
            $ids[] = Report::create([
                'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
                'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
            ])->id;
        }

        Livewire::test(BulkOperations::class)
            ->callTableBulkAction('send_unsent', $ids, ['channel' => 'klaviyo'])
            ->assertSet('running', false);          // refused — no run started

        $this->assertSame(0, BulkOperationRun::count());
        Http::assertNothingSent();                  // nothing emailed
    }

    public function test_nothing_eligible_starts_no_run(): void
    {
        Http::fake();
        $alreadySent = $this->makeReport();
        $alreadySent->recordKlaviyoSend(true, 'prior');

        Livewire::test(BulkOperations::class)
            ->callTableBulkAction('send_unsent', [$alreadySent->id], ['channel' => 'klaviyo'])
            ->assertSet('running', false);

        $this->assertSame(0, BulkOperationRun::count());
        Http::assertNothingSent();
    }

    // ── A 429 leaves the report retriable (no send recorded, stays in remaining) ─
    public function test_rate_limited_report_is_left_retriable(): void
    {
        Http::fake(['*/api/events/' => Http::response('slow down', 429)]);
        $report = $this->makeReport();

        $page = Livewire::test(BulkOperations::class)
            ->callTableBulkAction('send_unsent', [$report->id], ['channel' => 'klaviyo']);

        // One poll hits the 429 → report stays pending, not sent, not failed.
        $page->call('processChunk');
        $this->assertTrue($page->get('running'));
        $this->assertSame(0, $page->get('succeeded'));
        $this->assertSame(0, $page->get('failed'));
        $this->assertSame([$report->id], $page->get('pendingIds'));   // still queued
        $this->assertFalse($report->fresh()->hasBeenSent());          // nothing recorded
    }

    // ── Failed (non-429) send never marks the report sent ────────────────────
    public function test_failed_send_is_counted_failed_not_sent(): void
    {
        Http::fake(['*/api/events/' => Http::response('boom', 500)]);
        $report = $this->makeReport();

        $page = Livewire::test(BulkOperations::class)
            ->callTableBulkAction('send_unsent', [$report->id], ['channel' => 'klaviyo']);
        $this->drain($page);

        $this->assertSame(0, $page->get('succeeded'));
        $this->assertSame(1, $page->get('failed'));
        $this->assertFalse($report->fresh()->hasBeenSent());
    }

    // ── THE KEY TEST: a resumed run does NOT re-send an already-sent report ──
    public function test_resumed_run_does_not_resend_already_sent_reports(): void
    {
        Http::fake(['*/api/events/' => Http::response([], 202)]);

        $a = $this->makeReport();
        $b = $this->makeReport();

        // Simulate an interrupted SEND run where A was ALREADY SENT in this run but
        // a crash left BOTH ids in remaining_ids (the worst case the guard defends).
        $a->recordKlaviyoSend(true, 'sent in the interrupted run');
        $run = BulkOperationRun::create([
            'started_by' => auth()->id(),
            'operation' => BulkOperationRun::OPERATION_SEND, 'channel' => 'klaviyo',
            'total' => 2, 'batch_ids' => [$a->id, $b->id], 'remaining_ids' => [$a->id, $b->id],
            'regenerated_count' => 0, 'failed_count' => 0, 'skipped_count' => 0,
            'status' => BulkOperationRun::STATUS_INTERRUPTED,
            'started_at' => now()->subMinutes(10), 'last_progress_at' => now()->subMinutes(5),
        ]);

        $page = Livewire::withQueryParams(['resume' => $run->id])
            ->test(BulkOperations::class)
            ->assertSet('running', true)
            ->assertSet('operation', 'send');

        $this->drain($page);

        // A is re-checked → already sent → SKIPPED (not re-sent). B is sent.
        $this->assertSame(1, $page->get('succeeded'));   // only B
        $this->assertSame(1, $page->get('skipped'));     // A skipped
        // Exactly ONE new event went out (B) — A was NOT emailed again.
        Http::assertSentCount(1);
        $this->assertTrue($b->fresh()->hasBeenSent());
    }

    // ── Regenerate action is unaffected by the send wiring ───────────────────
    public function test_regenerate_action_still_starts_a_regenerate_run(): void
    {
        $report = $this->makeReport();

        Livewire::test(BulkOperations::class)
            ->callTableBulkAction('regenerate', [$report->id])
            ->assertSet('operation', 'regenerate')
            ->assertSet('channel', null);

        $this->assertSame('regenerate', BulkOperationRun::firstOrFail()->operation);
    }

    // ── Per-channel chunk sizes ──────────────────────────────────────────────
    public function test_send_chunk_sizes_are_per_channel(): void
    {
        $this->assertSame(3, BulkOperations::SEND_CHUNK_SIZES[BulkOperationRun::CHANNEL_KLAVIYO]);
        $this->assertSame(1, BulkOperations::SEND_CHUNK_SIZES[BulkOperationRun::CHANNEL_APP]);
    }
}
