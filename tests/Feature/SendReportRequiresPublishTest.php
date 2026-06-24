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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A report must be PUBLISHED before it can be emailed to the customer — sending a
 * draft link is the live bug being fixed. Both send channels (Klaviyo + App) are
 * disabled with a "publish first" reason while unpublished, the server-side guard
 * refuses regardless of the UI, and once published both channels work as before.
 */
class SendReportRequiresPublishTest extends TestCase
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

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Klaviyo fully enabled + a client email, so the ONLY thing that can block
        // sending in these tests is the unpublished state.
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
    }

    private function makeReport(string $status): Report
    {
        $client = Client::create(['name' => 'Jane Owner', 'email' => 'owner@example.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'order_id' => 'PUB-1', 'sample_id' => 'PUB-1', 'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => $status, 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
    }

    public function test_both_channels_disabled_when_report_is_a_draft(): void
    {
        Http::fake();
        Mail::fake();
        $report = $this->makeReport('draft');

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionDisabled('send_via_klaviyo')
            ->assertActionDisabled('send_via_app');

        // Nothing leaves the building while unpublished.
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_server_side_guard_returns_publish_first_for_a_draft(): void
    {
        $report = $this->makeReport('draft');

        $page = new EditReport();
        $rp = new \ReflectionProperty($page, 'record');
        $rp->setAccessible(true);
        $rp->setValue($page, $report);

        $expected = "Publish this report before sending it — the link won't work for the customer until it's published.";

        foreach (['unpublishedSendReason', 'sendReportBlockedReason', 'appSendBlockedReason'] as $method) {
            $m = new \ReflectionMethod($page, $method);
            $m->setAccessible(true);
            // Publish-first wins even though Klaviyo is enabled and an email exists.
            $this->assertSame($expected, $m->invoke($page), "{$method} should require publish first");
        }

        // Once published, the publish gate clears (other channel checks can still apply).
        $report->update(['status' => 'published']);
        $page2 = new EditReport();
        $rp->setValue($page2, $report->fresh());
        $m = new \ReflectionMethod($page2, 'unpublishedSendReason');
        $m->setAccessible(true);
        $this->assertNull($m->invoke($page2));
    }

    public function test_both_channels_work_once_published(): void
    {
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        Mail::fake();
        $report = $this->makeReport('published');

        // Klaviyo channel: enabled and fires.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionEnabled('send_via_klaviyo')
            ->assertActionEnabled('send_via_app')
            ->callAction('send_via_klaviyo')
            ->assertNotified('Report sent to Klaviyo');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/events/'));

        // App channel: emails the client.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_app')
            ->assertNotified('Report sent');

        Mail::assertSent(\App\Mail\ReportPublishedMail::class);
    }
}
