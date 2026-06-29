<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Klaviyo\EventRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side Klaviyo Events API client.
 *
 * One send entry point (sendEvent) driven by the extensible EventRegistry, plus
 * a lightweight testConnection() for the Phase 3 UI. Every path is total: this
 * service NEVER throws to the caller and NEVER logs the API key. All config is
 * read from the Phase 1 Setting::KLAVIYO_* values (encrypted key via
 * getDecrypted; revision / base URL fall back to their *_DEFAULT).
 */
class KlaviyoService
{
    /**
     * HTTP timeout (seconds) for every Klaviyo request.
     */
    protected const TIMEOUT = 10;

    /**
     * Send an event by its internal registry key.
     *
     * @param  string  $eventKey  registry key (e.g. 'report_published')
     * @param  string  $profileEmail  recipient profile identifier
     * @param  array  $payloadData  raw values for the registry's property / unique_id builders
     * @return array{ok: bool, status: int, retryable: bool, message: string}
     */
    public function sendEvent(string $eventKey, string $profileEmail, array $payloadData = []): array
    {
        $definition = EventRegistry::get($eventKey);

        // Unknown event — fail softly, no HTTP, no throw.
        if ($definition === null) {
            Log::warning('Klaviyo: unknown event key — nothing sent.', ['event' => $eventKey]);

            return ['ok' => false, 'status' => 0, 'retryable' => false, 'message' => "Unknown Klaviyo event key [{$eventKey}]"];
        }

        // Config gate — graceful NO-OP with no HTTP call when disabled or unconfigured.
        if (! $this->isEnabled() || blank($this->apiKey())) {
            Log::info('Klaviyo: skipped (disabled or not configured).', [
                'event' => $eventKey,
                'enabled' => $this->isEnabled(),
                'has_key' => filled($this->apiKey()),
            ]);

            return ['ok' => false, 'status' => 0, 'retryable' => false, 'message' => 'Klaviyo disabled or not configured'];
        }

        $body = $this->buildEventBody($definition, $profileEmail, $payloadData);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(self::TIMEOUT)
                ->post($this->baseUrl().'/api/events/', $body);

            // Klaviyo accepts a valid event with 202 Accepted.
            if ($response->status() === 202) {
                Log::info('Klaviyo: event accepted.', ['event' => $eventKey, 'status' => 202]);

                return ['ok' => true, 'status' => 202, 'retryable' => false, 'message' => 'Accepted'];
            }

            // 429 Too Many Requests — RETRYABLE. The send didn't happen because we
            // were throttled, not because the request was bad. Flag it so a caller
            // (bulk send) can leave the report for a later retry rather than burning
            // it as a permanent failure. Single-send just surfaces the failure.
            if ($response->status() === 429) {
                Log::warning('Klaviyo: rate limited (429) — retryable.', [
                    'event' => $eventKey,
                    'status' => 429,
                ]);

                return ['ok' => false, 'status' => 429, 'retryable' => true, 'message' => 'Rate limited by Klaviyo (429) — retry later.'];
            }

            Log::error('Klaviyo: event rejected.', [
                'event' => $eventKey,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'status' => $response->status(), 'retryable' => false, 'message' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('Klaviyo: event request failed.', [
                'event' => $eventKey,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'retryable' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Lightweight connection check for the admin UI (Phase 3). Calls Get
     * Accounts and parses the organization name. Never throws.
     *
     * @return array{ok: bool, account_name: ?string, message: string}
     */
    public function testConnection(): array
    {
        if (blank($this->apiKey())) {
            return ['ok' => false, 'account_name' => null, 'message' => 'Klaviyo API key not configured'];
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(self::TIMEOUT)
                ->get($this->baseUrl().'/api/accounts/');

            if ($response->successful()) {
                $name = $response->json('data.0.attributes.contact_information.organization_name')
                    ?? $response->json('data.0.attributes.contact_information.default_sender_name');

                Log::info('Klaviyo: connection test ok.', ['status' => $response->status()]);

                return ['ok' => true, 'account_name' => $name, 'message' => 'Connected'];
            }

            Log::warning('Klaviyo: connection test failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'account_name' => null, 'message' => 'HTTP '.$response->status()];
        } catch (\Throwable $e) {
            Log::error('Klaviyo: connection test error.', ['error' => $e->getMessage()]);

            return ['ok' => false, 'account_name' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Build the JSON:API Create Event body. Uses the relationship-style nested
     * "data" wrappers for profile and metric (verified against Klaviyo revision
     * 2026-04-15). unique_id is only included when the definition produces one.
     *
     * @return array<string, mixed>
     */
    protected function buildEventBody(\App\Services\Klaviyo\EventDefinition $definition, string $profileEmail, array $payloadData): array
    {
        $attributes = [
            'profile' => [
                'data' => [
                    'type' => 'profile',
                    'attributes' => ['email' => $profileEmail],
                ],
            ],
            'metric' => [
                'data' => [
                    'type' => 'metric',
                    'attributes' => ['name' => $definition->metric],
                ],
            ],
            'properties' => $definition->properties($payloadData),
            'time' => $payloadData['time'] ?? Carbon::now()->toIso8601String(),
        ];

        $uniqueId = $definition->uniqueId($payloadData);
        if (filled($uniqueId)) {
            $attributes['unique_id'] = $uniqueId;
        }

        return ['data' => ['type' => 'event', 'attributes' => $attributes]];
    }

    /**
     * Auth + revision + JSON headers. Note: the API key is sent here but is
     * never logged anywhere.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Klaviyo-API-Key '.$this->apiKey(),
            'revision' => $this->revision(),
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ];
    }

    protected function isEnabled(): bool
    {
        return filter_var(Setting::get(Setting::KLAVIYO_ENABLED), FILTER_VALIDATE_BOOLEAN);
    }

    protected function apiKey(): ?string
    {
        return Setting::getDecrypted(Setting::KLAVIYO_API_KEY);
    }

    protected function revision(): string
    {
        return Setting::get(Setting::KLAVIYO_REVISION) ?: Setting::KLAVIYO_REVISION_DEFAULT;
    }

    protected function baseUrl(): string
    {
        return rtrim(Setting::get(Setting::KLAVIYO_BASE_URL) ?: Setting::KLAVIYO_BASE_URL_DEFAULT, '/');
    }
}
