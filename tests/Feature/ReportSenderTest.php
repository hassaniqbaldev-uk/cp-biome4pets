<?php

namespace Tests\Feature;

use App\Mail\ReportPublishedMail;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Test;
use App\Support\ReportSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The shared single send path. Single-send actions AND bulk send both go through
 * ReportSender, so it owns the publish-gate, email check, per-channel dispatch and
 * send-recording. A success flips hasBeenSent(); a hard failure records a failed
 * attempt but never "sent"; a Klaviyo 429 records nothing and is flagged retryable.
 */
class ReportSenderTest extends TestCase
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

    private function enableKlaviyo(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
    }

    private function makeReport(string $status = 'published', string $email = 'owner@example.test'): Report
    {
        $client = Client::create(['name' => 'Jane', 'email' => $email]);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(),
            'sample_id' => 'S'.uniqid(), 'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => $status,
        ]);
    }

    // ── Klaviyo ──────────────────────────────────────────────────────────────
    public function test_klaviyo_success_records_a_send_and_flips_sent_status(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        $report = $this->makeReport();

        $result = ReportSender::send($report, ReportSender::CHANNEL_KLAVIYO);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertFalse($result['retryable']);
        $report->refresh();
        $this->assertTrue($report->klaviyo_last_result['ok']);
        $this->assertSame('Report sent to Klaviyo', $report->klaviyo_last_result['message']);
        $this->assertTrue($report->hasBeenSent());

        // Same event endpoint single-send used.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/events/'));
    }

    public function test_klaviyo_hard_failure_records_attempt_but_not_sent(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response('upstream boom', 500)]);
        $report = $this->makeReport();

        $result = ReportSender::send($report, ReportSender::CHANNEL_KLAVIYO);

        $this->assertFalse($result['ok']);
        $this->assertSame('send_failed', $result['reason']);
        $this->assertFalse($result['retryable']);
        $report->refresh();
        $this->assertNotNull($report->klaviyo_last_sent_at);          // attempt recorded
        $this->assertFalse($report->klaviyo_last_result['ok']);       // ...as a failure
        $this->assertFalse($report->hasBeenSent());                   // NOT marked sent
    }

    public function test_klaviyo_429_is_retryable_and_records_nothing(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response('rate limited', 429)]);
        $report = $this->makeReport();

        $result = ReportSender::send($report, ReportSender::CHANNEL_KLAVIYO);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['retryable']);
        $this->assertSame('rate_limited', $result['reason']);
        // Records NOTHING — the report is left cleanly retryable.
        $report->refresh();
        $this->assertNull($report->klaviyo_last_sent_at);
        $this->assertFalse($report->hasBeenSent());
    }

    // ── App (SMTP) ───────────────────────────────────────────────────────────
    public function test_app_success_sends_the_mail_and_records_it(): void
    {
        Mail::fake();
        $report = $this->makeReport();

        $result = ReportSender::send($report, ReportSender::CHANNEL_APP);

        $this->assertTrue($result['ok']);
        Mail::assertSent(ReportPublishedMail::class);
        $report->refresh();
        $this->assertTrue($report->app_last_result['ok']);
        $this->assertTrue($report->hasBeenSent());
    }

    public function test_app_transport_failure_records_failure_not_sent(): void
    {
        $report = $this->makeReport();
        // Force the transport to throw, exercising the try/catch path.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp boom'));

        $result = ReportSender::send($report, ReportSender::CHANNEL_APP);

        $this->assertFalse($result['ok']);
        $this->assertSame('send_failed', $result['reason']);
        $this->assertStringContainsString('smtp boom', $result['message']);
        $report->refresh();
        $this->assertNotNull($report->app_last_sent_at);
        $this->assertFalse($report->app_last_result['ok']);
        $this->assertFalse($report->hasBeenSent());
    }

    // ── Skips (no send, no record) ───────────────────────────────────────────
    public function test_unpublished_report_is_skipped_not_sent(): void
    {
        Http::fake();
        Mail::fake();
        $report = $this->makeReport(status: 'draft');

        foreach ([ReportSender::CHANNEL_KLAVIYO, ReportSender::CHANNEL_APP] as $channel) {
            $result = ReportSender::send($report, $channel);
            $this->assertTrue($result['skipped']);
            $this->assertSame('not_published', $result['reason']);
        }

        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertNull($report->fresh()->klaviyo_last_sent_at);
        $this->assertNull($report->fresh()->app_last_sent_at);
    }

    public function test_report_without_client_email_is_skipped(): void
    {
        Http::fake();
        Mail::fake();
        $report = $this->makeReport(email: '');   // blank client email

        $result = ReportSender::send($report, ReportSender::CHANNEL_KLAVIYO);

        $this->assertTrue($result['skipped']);
        $this->assertSame('no_email', $result['reason']);
        Http::assertNothingSent();
        $this->assertNull($report->fresh()->klaviyo_last_sent_at);
    }
}
