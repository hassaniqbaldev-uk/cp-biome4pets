<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\OpenAiService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Usage tracking: each successful OpenAI call records an AiUsageEvent (call_type,
 * resolved model, token counts). It is a SIDE-WRITE — absent usage records nothing,
 * and a write failure is swallowed so it can never break a live generation. The
 * settings totals aggregate the rows.
 */
class AiUsageTrackingTest extends TestCase
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

    /** Invoke the private recordUsage() to exercise the side-write directly (the HTTP
     *  layer uses file_get_contents and can't be faked, so we test the unit). */
    private function record(string $type, string $model, mixed $usage, ?int $reportId = null): void
    {
        $m = new \ReflectionMethod(OpenAiService::class, 'recordUsage');
        $m->setAccessible(true);
        $m->invoke(new OpenAiService, $type, $model, $usage, $reportId);
    }

    private const USAGE = ['prompt_tokens' => 1200, 'completion_tokens' => 800, 'total_tokens' => 2000];

    // ── Recording ────────────────────────────────────────────────────────

    public function test_records_a_row_for_a_successful_interpretation_call(): void
    {
        $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', self::USAGE);

        $this->assertSame(1, AiUsageEvent::count());
        $row = AiUsageEvent::first();
        $this->assertSame('interpretation', $row->call_type);
        $this->assertSame('gpt-4o', $row->model);
        $this->assertSame(1200, $row->prompt_tokens);
        $this->assertSame(800, $row->completion_tokens);
        $this->assertSame(2000, $row->total_tokens);
        $this->assertNull($row->report_id);
    }

    public function test_records_a_row_for_a_successful_plan_copy_call(): void
    {
        $this->record(AiUsageEvent::TYPE_PLAN_COPY, 'gpt-4o-mini', ['prompt_tokens' => 1500, 'completion_tokens' => 1253, 'total_tokens' => 2753]);

        $row = AiUsageEvent::first();
        $this->assertSame('plan_copy', $row->call_type);
        $this->assertSame('gpt-4o-mini', $row->model);
        $this->assertSame(2753, $row->total_tokens);
    }

    public function test_links_to_a_report_when_a_report_id_is_given(): void
    {
        $report = $this->makeReport();
        $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', self::USAGE, $report->id);

        $row = AiUsageEvent::first();
        $this->assertSame($report->id, $row->report_id);
        $this->assertTrue($row->report->is($report));
    }

    public function test_no_row_when_usage_is_absent_or_unusable(): void
    {
        // A failed / usage-less response: null, empty, or non-numeric usage.
        $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', null);
        $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', []);
        $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', 'not-an-array');
        $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', ['prompt_tokens' => 'x', 'completion_tokens' => null, 'total_tokens' => null]);

        $this->assertSame(0, AiUsageEvent::count());
    }

    public function test_a_tracking_write_failure_is_swallowed_and_never_throws(): void
    {
        // Simulate a broken table: the side-write must log + swallow, never bubble up.
        Schema::drop('ai_usage_events');

        try {
            $this->record(AiUsageEvent::TYPE_INTERPRETATION, 'gpt-4o', self::USAGE);
        } catch (\Throwable $e) {
            $this->fail('recordUsage must swallow write failures, but threw: '.$e->getMessage());
        }

        $this->assertTrue(true); // reached here → the failure was swallowed
    }

    // ── Wiring: both call sites + the success guard ──────────────────────

    public function test_both_generation_calls_wire_record_usage_with_the_right_type(): void
    {
        $src = file_get_contents(app_path('Services/OpenAiService.php'));

        $this->assertStringContainsString('$this->recordUsage(AiUsageEvent::TYPE_INTERPRETATION, $model, $decoded[\'usage\'] ?? null, $reportId)', $src);
        $this->assertStringContainsString('$this->recordUsage(AiUsageEvent::TYPE_PLAN_COPY, $model, $decoded[\'usage\'] ?? null, $reportId)', $src);
        // Only recorded on a non-error response (skips failed calls).
        $this->assertSame(2, substr_count($src, "if (! isset(\$decoded['error'])) {\n                \$this->recordUsage("));
        // Logging is kept (tracking is additive, not a replacement).
        $this->assertStringContainsString("Log::info('OpenAI raw API response'", $src);
        $this->assertStringContainsString("Log::info('Plan copy generation: raw API response'", $src);
    }

    // ── Totals view ──────────────────────────────────────────────────────

    public function test_summary_aggregates_by_type_and_window(): void
    {
        // Two interpretations + one plan copy; one interpretation is 40 days old.
        AiUsageEvent::create(['call_type' => 'interpretation', 'model' => 'gpt-4o', 'prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500]);
        AiUsageEvent::create(['call_type' => 'plan_copy', 'model' => 'gpt-4o', 'prompt_tokens' => 2000, 'completion_tokens' => 700, 'total_tokens' => 2700]);
        $old = AiUsageEvent::create(['call_type' => 'interpretation', 'model' => 'gpt-4o', 'prompt_tokens' => 100, 'completion_tokens' => 100, 'total_tokens' => 200]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $s = AiUsageEvent::summary();

        // Overall (all three).
        $this->assertSame(3, $s['overall']['calls']);
        $this->assertSame(4400, $s['overall']['total_tokens']);   // 1500 + 2700 + 200
        $this->assertSame(3100, $s['overall']['prompt_tokens']);  // 1000 + 2000 + 100

        // By type.
        $this->assertSame(2, $s['by_type']['interpretation']['calls']);
        $this->assertSame(1700, $s['by_type']['interpretation']['total_tokens']); // 1500 + 200
        $this->assertSame(1, $s['by_type']['plan_copy']['calls']);
        $this->assertSame(2700, $s['by_type']['plan_copy']['total_tokens']);

        // Last 30 days excludes the 40-day-old row.
        $this->assertSame(2, $s['last_30_days']['calls']);
        $this->assertSame(4200, $s['last_30_days']['total_tokens']); // 1500 + 2700
    }

    public function test_summary_is_zeroed_and_safe_when_the_table_is_missing(): void
    {
        Schema::drop('ai_usage_events');

        $s = AiUsageEvent::summary();

        $this->assertSame(0, $s['overall']['calls']);
        $this->assertSame(0, $s['overall']['total_tokens']);
        $this->assertSame(0, $s['by_type']['interpretation']['calls']);
        $this->assertSame(0, $s['last_30_days']['total_tokens']);
    }

    private function makeReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(),
            'sample_id' => 'S'.uniqid(), 'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => 'draft',
        ]);
    }
}
