<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SendReportActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated in-memory sqlite with the full schema — never touches MySQL.
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

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function enableKlaviyo(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
    }

    protected function makeReport(string $email = 'owner@example.com'): Report
    {
        $client = Client::create(['name' => 'Jane Owner', 'email' => $email]);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        // sample_id / report_date live on the Test now; the report reads them
        // (and builds its slug) through the Report→Test proxy.
        $test = Test::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'order_id' => 'SEND-1',
            'sample_id' => 'SEND-1',
            'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'test_id' => $test->id,
            'status' => 'published',
        ]);
    }

    public function test_send_report_sends_correct_per_report_payload_and_records_success(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_klaviyo')
            ->assertNotified('Report sent to Klaviyo');

        Http::assertSent(function ($request) use ($report) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/events/')
                && data_get($body, 'data.attributes.profile.data.attributes.email') === 'owner@example.com'
                && data_get($body, 'data.attributes.metric.data.attributes.name') === 'Report Published'
                && data_get($body, 'data.attributes.properties.pet_name') === 'Biscuit'
                && data_get($body, 'data.attributes.properties.client_name') === 'Jane Owner'
                && data_get($body, 'data.attributes.properties.report_date') === 'June 15, 2026'
                && str_contains((string) data_get($body, 'data.attributes.properties.report_url'), '/report/'.$report->public_token)
                // Idempotency key = report_id + send time. It now carries a time
                // suffix so a deliberate re-send is a distinct, delivered event
                // (instead of being deduped against the first send).
                && str_starts_with((string) data_get($body, 'data.attributes.unique_id'), 'report_published_'.$report->id.'_');
        });

        // Per-report last-sent state recorded.
        $fresh = $report->fresh();
        $this->assertNotNull($fresh->klaviyo_last_sent_at);
        $this->assertTrue($fresh->klaviyo_last_result['ok']);
        $this->assertSame('Report sent to Klaviyo', $fresh->klaviyo_last_result['message']);
        $this->assertStringContainsString('OK', $fresh->klaviyoLastSentSummary());
    }

    public function test_send_report_failure_records_failure_and_surfaces_error(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response('upstream boom', 500)]);
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_klaviyo')
            ->assertNotified('Send failed');

        $fresh = $report->fresh();
        $this->assertNotNull($fresh->klaviyo_last_sent_at);
        $this->assertFalse($fresh->klaviyo_last_result['ok']);
        $this->assertStringContainsString('boom', $fresh->klaviyo_last_result['message']);
        $this->assertStringContainsString('Failed', $fresh->klaviyoLastSentSummary());
    }

    public function test_action_is_disabled_and_sends_nothing_when_integration_off(): void
    {
        // Key present but master toggle OFF.
        Setting::set(Setting::KLAVIYO_ENABLED, '0');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
        Http::fake();
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionDisabled('send_via_klaviyo');

        Http::assertNothingSent();
        $this->assertNull($report->fresh()->klaviyo_last_sent_at);
    }

    public function test_action_is_disabled_when_no_api_key(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1'); // enabled but no key
        Http::fake();
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionDisabled('send_via_klaviyo');

        Http::assertNothingSent();
    }

    public function test_action_is_disabled_when_no_client_email(): void
    {
        $this->enableKlaviyo();
        Http::fake();
        // Client with an empty email — guard must block and never call with it.
        $report = $this->makeReport('');

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionDisabled('send_via_klaviyo');

        Http::assertNothingSent();
    }

    public function test_runtime_guard_blocks_send_with_blank_email_even_if_invoked(): void
    {
        // Belt-and-suspenders: the action closure re-checks before calling the
        // service, so a blank email never reaches sendEvent.
        $this->enableKlaviyo();
        Http::fake();
        $report = $this->makeReport('');

        $page = new EditReport();
        $rp = new \ReflectionProperty($page, 'record');
        $rp->setAccessible(true);
        $rp->setValue($page, $report);

        $method = new \ReflectionMethod($page, 'sendReportBlockedReason');
        $method->setAccessible(true);

        $this->assertSame('No client email on this report', $method->invoke($page));
        Http::assertNothingSent();
    }

    public function test_already_sent_report_can_be_resent_to_klaviyo_as_a_distinct_event(): void
    {
        // The client wants repeat sends to actually deliver. The old stable unique_id
        // (report_id only) made Klaviyo dedupe a second send into nothing; the time
        // suffix makes each deliberate re-send a distinct, delivered event.
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        $report = $this->makeReport();

        // First send.
        Carbon::setTestNow('2026-06-25 12:00:00');
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_klaviyo')
            ->assertNotified('Report sent to Klaviyo');

        $firstSentAt = $report->fresh()->klaviyo_last_sent_at;
        $this->assertNotNull($firstSentAt);
        $this->assertTrue($report->fresh()->klaviyoHasBeenSent());

        // The action stays ENABLED after a successful send — re-sends are allowed.
        Livewire::test(EditReport::class, ['record' => $report->fresh()->getRouteKey()])
            ->assertActionEnabled('send_via_klaviyo');

        // Second, deliberate send a few seconds later.
        Carbon::setTestNow('2026-06-25 12:00:05');
        Livewire::test(EditReport::class, ['record' => $report->fresh()->getRouteKey()])
            ->callAction('send_via_klaviyo')
            ->assertNotified('Report sent to Klaviyo');

        // Both events actually reached Klaviyo...
        Http::assertSentCount(2);

        // ...with DISTINCT unique_ids, so the re-send isn't deduped away.
        $ids = [];
        Http::recorded(function ($request) use (&$ids): bool {
            $ids[] = data_get($request->data(), 'data.attributes.unique_id');

            return true;
        });
        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertStringStartsWith('report_published_'.$report->id.'_', $ids[0]);
        $this->assertStringStartsWith('report_published_'.$report->id.'_', $ids[1]);

        // Last-sent state advanced to the re-send.
        $this->assertTrue($report->fresh()->klaviyo_last_sent_at->gt($firstSentAt));

        Carbon::setTestNow();
    }

    public function test_resend_confirmation_notice_appears_only_after_a_send(): void
    {
        // The confirm-if-already-sent guard: the dated "already sent" warning shown in
        // the send modal is produced only on a repeat — the first send is unchanged.
        $this->enableKlaviyo();
        $report = $this->makeReport();

        $page = new EditReport();
        $rp = new \ReflectionProperty($page, 'record');
        $rp->setAccessible(true);
        $rp->setValue($page, $report);
        $notice = new \ReflectionMethod($page, 'klaviyoResendNotice');
        $notice->setAccessible(true);

        // Before any send: no notice.
        $this->assertNull($notice->invoke($page));

        // After a successful send: a dated re-send notice appears.
        Carbon::setTestNow('2026-06-25 12:00:00');
        $report->recordKlaviyoSend(true, 'Report sent to Klaviyo');
        $rp->setValue($page, $report->fresh());

        $message = $notice->invoke($page);
        $this->assertNotNull($message);
        $this->assertStringContainsString('already sent to Klaviyo', $message);

        Carbon::setTestNow();
    }
}
