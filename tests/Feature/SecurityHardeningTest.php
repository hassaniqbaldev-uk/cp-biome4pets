<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\PaidActionLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Monolog\Handler\TestHandler;
use RuntimeException;
use Tests\TestCase;

/**
 * Security hardening pass (medium/low defence-in-depth):
 *   M1 — OpenAiService never logs PII/AI text (only lengths/usage/status).
 *   M3 — Password::default() enforces the strong-password baseline.
 *   M5b — CsvParserService caps the row count.
 *   L1 — public report routes are throttled (429 past the cap).
 *   L2 — paid admin actions are per-user rate limited.
 */
class SecurityHardeningTest extends TestCase
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
    }

    // ───────────────────────── M1: no PII in logs ─────────────────────────

    public function test_openai_service_does_not_log_the_response_content(): void
    {
        // A test subclass that returns a crafted (non-JSON) body carrying a PII
        // canary as the message content — this drives the "failed to parse" path
        // that USED to dump the content into the log.
        $canary = 'PII-CANARY-Biscuit-owner-health-notes-9f3a';

        $service = new class extends OpenAiService
        {
            public string $body = '';

            protected function requestChatCompletion(string $apiKey, string $payload): string|false
            {
                return $this->body;
            }
        };
        $service->body = json_encode([
            'usage' => ['total_tokens' => 123],
            'choices' => [['message' => ['content' => $canary]]],
        ]);

        config(['services.openai.api_key' => 'test-key']);

        // Capture everything written to the log during the call.
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);

        $service->generatePlanCopy(['pet_name' => 'Biscuit'], ['steps' => []]);

        // The canary (PII) must appear in NO log record — message or context.
        foreach ($handler->getRecords() as $record) {
            $serialised = $record->message.' '.json_encode($record->context);
            $this->assertStringNotContainsString(
                $canary,
                $serialised,
                'OpenAiService leaked AI/PII content into the log'
            );
        }

        // And it should still log useful non-identifying metadata: a content_length.
        $loggedLength = collect($handler->getRecords())
            ->contains(fn ($r) => array_key_exists('content_length', $r->context));
        $this->assertTrue($loggedLength, 'expected a content_length debug field in the log');
    }

    // ───────────────────────── M3: password policy ─────────────────────────

    public function test_password_default_rejects_weak_and_short_passwords(): void
    {
        $rejected = [
            'short1A',             // too short (< 12)
            'alllowercase123',     // no uppercase
            'ALLUPPERCASE123',     // no lowercase
            'NoNumbersHereAtAll',  // no digits
        ];

        foreach ($rejected as $weak) {
            $this->assertTrue(
                Validator::make(['password' => $weak], ['password' => Password::default()])->fails(),
                "weak password should be rejected: {$weak}"
            );
        }

        $this->assertTrue(
            Validator::make(['password' => 'ValidPass1234'], ['password' => Password::default()])->passes(),
            'a strong password should be accepted'
        );
    }

    // ───────────────────────── M5b: CSV row cap ─────────────────────────

    public function test_csv_parser_rejects_a_file_exceeding_the_row_cap(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csvcap').'.csv';
        // Header + 6 data rows, with an injected cap of 5 → must be rejected.
        $rows = ['Phylum,Species,%_hits'];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = "Firmicutes,Species {$i},1";
        }
        file_put_contents($path, implode("\n", $rows));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the maximum');

        try {
            (new CsvParserService)->parse($path, maxRows: 5);
        } finally {
            @unlink($path);
        }
    }

    public function test_csv_parser_accepts_a_file_within_the_row_cap(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csvok').'.csv';
        file_put_contents($path, implode("\n", [
            'Phylum,Species,%_hits',
            'Firmicutes,Lactobacillus reuteri,30',
            'Bacteroidetes,Bacteroides fragilis,20',
        ]));

        $result = (new CsvParserService)->parse($path, maxRows: 5);
        @unlink($path);

        $this->assertArrayHasKey('phylum_totals', $result);
        $this->assertArrayHasKey('Firmicutes', $result['phylum_totals']);
    }

    // ───────────────────────── L1: report route throttling ─────────────────────────

    public function test_report_route_limiters_have_the_expected_caps(): void
    {
        $limiter = app(\Illuminate\Cache\RateLimiter::class);

        $report = $limiter->limiter('report')(Request::create('/report/x'));
        $pdf = $limiter->limiter('report-pdf')(Request::create('/report/x/pdf'));

        $this->assertSame(60, $report->maxAttempts);
        $this->assertSame(30, $pdf->maxAttempts);
    }

    public function test_report_route_returns_429_past_the_threshold(): void
    {
        // Re-register the 'report' limiter to a tiny cap so we can prove the
        // throttle middleware is wired and returns 429 without hammering.
        RateLimiter::for('report', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        $report = $this->makeReport();
        $url = '/report/'.$report->public_token;

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->get($url)->assertStatus(429);
    }

    // ───────────────────────── L2: paid action limiter ─────────────────────────

    public function test_paid_action_limiter_blocks_past_the_cap(): void
    {
        // First 3 attempts pass, the 4th is blocked.
        $this->assertFalse(PaidActionLimiter::exceeded('unit-test-action', 3));
        $this->assertFalse(PaidActionLimiter::exceeded('unit-test-action', 3));
        $this->assertFalse(PaidActionLimiter::exceeded('unit-test-action', 3));
        $this->assertTrue(PaidActionLimiter::exceeded('unit-test-action', 3));

        // A different action key has its own independent budget.
        $this->assertFalse(PaidActionLimiter::exceeded('other-action', 3));
    }

    private function makeReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'KMS734', 'sample_id' => 'KMS734',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
        $report->steps()->create(['title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }
}
