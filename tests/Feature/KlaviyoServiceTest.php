<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\KlaviyoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KlaviyoServiceTest extends TestCase
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
    }

    /**
     * Enable Klaviyo and store an encrypted key, exactly as the Phase 1 settings
     * page would, so the service sees a fully-configured integration.
     */
    protected function configureKlaviyo(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
        // revision + base URL left unset -> service falls back to *_DEFAULT.
    }

    /**
     * The canonical report_published payload used across the send tests.
     */
    protected function reportPayload(): array
    {
        return [
            'report_id' => 42,
            'pet_name' => 'Biscuit',
            'report_url' => 'https://example.test/report/biscuit-8b3342',
            'report_date' => '2026-06-15',
            'client_name' => 'Jane Owner',
            'time' => '2026-06-15T10:00:00+00:00',
        ];
    }

    public function test_send_event_builds_correct_endpoint_headers_and_jsonapi_body(): void
    {
        $this->configureKlaviyo();
        Http::fake(['*/api/events/' => Http::response([], 202)]);

        $result = (new KlaviyoService())->sendEvent(
            'report_published',
            'owner@example.test',
            $this->reportPayload(),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(202, $result['status']);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://a.klaviyo.com/api/events/'
                // Headers: auth + revision (default) + json
                && $request->hasHeader('Authorization', 'Klaviyo-API-Key pk_test_TOPSECRET')
                && $request->hasHeader('revision', Setting::KLAVIYO_REVISION_DEFAULT)
                && $request->hasHeader('accept', 'application/json')
                && $request->hasHeader('content-type', 'application/json')
                // JSON:API envelope
                && data_get($body, 'data.type') === 'event'
                // Nested relationship-style "data" wrappers (revision 2026-04-15)
                && data_get($body, 'data.attributes.profile.data.type') === 'profile'
                && data_get($body, 'data.attributes.profile.data.attributes.email') === 'owner@example.test'
                && data_get($body, 'data.attributes.metric.data.type') === 'metric'
                && data_get($body, 'data.attributes.metric.data.attributes.name') === 'Report Published'
                // snake_case properties from the registry builder
                && data_get($body, 'data.attributes.properties.pet_name') === 'Biscuit'
                && data_get($body, 'data.attributes.properties.report_url') === 'https://example.test/report/biscuit-8b3342'
                && data_get($body, 'data.attributes.properties.report_date') === '2026-06-15'
                && data_get($body, 'data.attributes.properties.client_name') === 'Jane Owner'
                // idempotency key = report_id + send time (varies per send so a
                // deliberate re-send is a distinct, delivered event), plus the time.
                && data_get($body, 'data.attributes.unique_id') === 'report_published_42_2026-06-15T10:00:00+00:00'
                && data_get($body, 'data.attributes.time') === '2026-06-15T10:00:00+00:00';
        });
    }

    public function test_non_202_response_returns_failure_with_status_captured(): void
    {
        $this->configureKlaviyo();
        Http::fake(['*/api/events/' => Http::response(['errors' => [['detail' => 'bad request']]], 400)]);

        $result = (new KlaviyoService())->sendEvent('report_published', 'owner@example.test', $this->reportPayload());

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('bad request', $result['message']);
    }

    public function test_network_exception_returns_failure_and_never_throws(): void
    {
        $this->configureKlaviyo();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out');
        });

        $result = (new KlaviyoService())->sendEvent('report_published', 'owner@example.test', $this->reportPayload());

        // Reaching this line at all proves nothing was thrown to the caller.
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('timed out', $result['message']);
    }

    public function test_disabled_integration_makes_no_http_call(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '0');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_TOPSECRET');
        Http::fake();

        $result = (new KlaviyoService())->sendEvent('report_published', 'owner@example.test', $this->reportPayload());

        Http::assertNothingSent();
        $this->assertFalse($result['ok']);
        $this->assertSame('Klaviyo disabled or not configured', $result['message']);
    }

    public function test_blank_api_key_makes_no_http_call(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        // No key stored.
        Http::fake();

        $result = (new KlaviyoService())->sendEvent('report_published', 'owner@example.test', $this->reportPayload());

        Http::assertNothingSent();
        $this->assertFalse($result['ok']);
        $this->assertSame('Klaviyo disabled or not configured', $result['message']);
    }

    public function test_unknown_event_key_returns_failure_without_http(): void
    {
        $this->configureKlaviyo();
        Http::fake();

        $result = (new KlaviyoService())->sendEvent('does_not_exist', 'owner@example.test', []);

        Http::assertNothingSent();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unknown Klaviyo event key', $result['message']);
    }

    public function test_test_connection_parses_account_name_from_200_response(): void
    {
        $this->configureKlaviyo();
        Http::fake([
            '*/api/accounts/' => Http::response([
                'data' => [[
                    'type' => 'account',
                    'id' => 'ACC123',
                    'attributes' => [
                        'contact_information' => [
                            'organization_name' => 'Acme Pets Ltd',
                            'default_sender_name' => 'Acme Pets',
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $result = (new KlaviyoService())->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('Acme Pets Ltd', $result['account_name']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://a.klaviyo.com/api/accounts/'
                && $request->hasHeader('Authorization', 'Klaviyo-API-Key pk_test_TOPSECRET')
                && $request->hasHeader('revision', Setting::KLAVIYO_REVISION_DEFAULT);
        });
    }

    public function test_test_connection_fails_gracefully_without_key(): void
    {
        // Nothing configured.
        Http::fake();

        $result = (new KlaviyoService())->testConnection();

        Http::assertNothingSent();
        $this->assertFalse($result['ok']);
        $this->assertNull($result['account_name']);
    }
}
