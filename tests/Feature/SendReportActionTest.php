<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
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

        return Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => 'SEND-1',
            'report_date' => '2026-06-15',
            'status' => 'published',
        ]);
    }

    public function test_send_report_sends_correct_per_report_payload_and_records_success(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_report')
            ->assertNotified('Report sent to Klaviyo');

        Http::assertSent(function ($request) use ($report) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/events/')
                && data_get($body, 'data.attributes.profile.data.attributes.email') === 'owner@example.com'
                && data_get($body, 'data.attributes.metric.data.attributes.name') === 'Report Published'
                && data_get($body, 'data.attributes.properties.pet_name') === 'Biscuit'
                && data_get($body, 'data.attributes.properties.client_name') === 'Jane Owner'
                && data_get($body, 'data.attributes.properties.report_date') === 'June 15, 2026'
                && str_contains((string) data_get($body, 'data.attributes.properties.report_url'), '/report/'.$report->slug)
                // Phase 2 idempotency key, per report.
                && data_get($body, 'data.attributes.unique_id') === 'report_published_'.$report->id;
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
            ->callAction('send_report')
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
            ->assertActionDisabled('send_report');

        Http::assertNothingSent();
        $this->assertNull($report->fresh()->klaviyo_last_sent_at);
    }

    public function test_action_is_disabled_when_no_api_key(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1'); // enabled but no key
        Http::fake();
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionDisabled('send_report');

        Http::assertNothingSent();
    }

    public function test_action_is_disabled_when_no_client_email(): void
    {
        $this->enableKlaviyo();
        Http::fake();
        // Client with an empty email — guard must block and never call with it.
        $report = $this->makeReport('');

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionDisabled('send_report');

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
}
