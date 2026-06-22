<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class EmailIntegrationsActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate on an in-memory sqlite DB so we never touch dev data.
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

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        $this->actingAs(new User(['name' => 'Admin User', 'email' => 'admin@cp.agency', 'role' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function enableKlaviyo(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
    }

    public function test_page_renders_with_klaviyo_diagnostics(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('Connection status')
            ->assertSee('Last Klaviyo result');
    }

    public function test_test_connection_success_shows_account_and_connected_status(): void
    {
        $this->enableKlaviyo();
        Http::fake([
            '*/api/accounts/' => Http::response([
                'data' => [[
                    'type' => 'account',
                    'attributes' => ['contact_information' => ['organization_name' => 'Acme Pets Ltd']],
                ]],
            ], 200),
        ]);

        Livewire::test(Settings::class)
            ->call('runTestConnection')
            ->assertSet('connectionState.ok', true)
            ->assertSet('connectionState.account', 'Acme Pets Ltd')
            ->assertNotified('Klaviyo connected');

        // Persisted last-result reflects the success.
        $last = json_decode(Setting::get(Setting::KLAVIYO_LAST_RESULT), true);
        $this->assertTrue($last['ok']);
        $this->assertSame('Test connection', $last['action']);
        $this->assertStringContainsString('Acme Pets Ltd', $last['message']);
    }

    public function test_test_connection_failure_shows_error_and_not_connected(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/accounts/' => Http::response(['errors' => [['detail' => 'invalid key']]], 401)]);

        Livewire::test(Settings::class)
            ->call('runTestConnection')
            ->assertSet('connectionState.ok', false)
            ->assertNotified('Klaviyo connection failed');

        $last = json_decode(Setting::get(Setting::KLAVIYO_LAST_RESULT), true);
        $this->assertFalse($last['ok']);
    }

    public function test_send_test_event_success_path(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response([], 202)]);

        Livewire::test(Settings::class)
            ->call('runSendTestEvent', 'owner@example.test')
            ->assertNotified('Test event sent');

        // Correct event hit the events endpoint with the dummy payload.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/events/')
                && data_get($body, 'data.attributes.metric.data.attributes.name') === 'Report Published'
                && data_get($body, 'data.attributes.profile.data.attributes.email') === 'owner@example.test'
                && data_get($body, 'data.attributes.properties.pet_name') === 'Test Pet'
                && data_get($body, 'data.attributes.properties.client_name') === 'Test Client';
        });

        $last = json_decode(Setting::get(Setting::KLAVIYO_LAST_RESULT), true);
        $this->assertTrue($last['ok']);
        $this->assertSame('Send test event', $last['action']);
    }

    public function test_send_test_event_failure_records_error(): void
    {
        $this->enableKlaviyo();
        Http::fake(['*/api/events/' => Http::response('boom', 500)]);

        Livewire::test(Settings::class)
            ->call('runSendTestEvent', 'owner@example.test')
            ->assertNotified('Test event failed');

        $last = json_decode(Setting::get(Setting::KLAVIYO_LAST_RESULT), true);
        $this->assertFalse($last['ok']);
    }

    public function test_actions_are_noop_when_disabled_even_if_called(): void
    {
        // Enabled OFF, but a key exists — the no-op gate lives in KlaviyoService.
        Setting::set(Setting::KLAVIYO_ENABLED, '0');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
        Http::fake();

        Livewire::test(Settings::class)
            ->call('runSendTestEvent', 'owner@example.test');

        Http::assertNothingSent();
    }

    public function test_no_key_status_is_key_missing_and_no_http(): void
    {
        // Nothing configured at all.
        Http::fake();

        Livewire::test(Settings::class)
            ->assertSee('Key missing')
            ->call('runTestConnection');

        Http::assertNothingSent();
    }

    public function test_last_result_persists_and_displays_after_reload(): void
    {
        // Seed a prior result as if a previous session ran it.
        Setting::set(Setting::KLAVIYO_LAST_RESULT, json_encode([
            'at' => '2026-06-15 09:00:00',
            'action' => 'Test connection',
            'ok' => true,
            'message' => 'Connected to Acme Pets Ltd',
        ]));

        Livewire::test(Settings::class)
            ->assertSee('Connected to Acme Pets Ltd')
            ->assertSee('2026-06-15 09:00:00');
    }
}
