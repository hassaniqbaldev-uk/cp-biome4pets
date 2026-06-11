<?php

namespace App\Services\Klaviyo;

use Closure;

/**
 * A single Klaviyo event definition: the stable metric name plus the two
 * builders that turn an internal payload array into the event's snake_case
 * properties and its idempotency key (unique_id).
 *
 * Definitions are inert data — KlaviyoService consumes them; it does not know
 * about any specific event. Adding a new event is one entry in EventRegistry.
 */
class EventDefinition
{
    /**
     * @param  string  $metric  Stable Klaviyo metric name (e.g. "Report Published").
     * @param  Closure(array): array<string, mixed>  $propertiesBuilder  payload -> snake_case props
     * @param  Closure(array): ?string  $uniqueIdBuilder  payload -> idempotency key (null = none)
     */
    public function __construct(
        public readonly string $metric,
        protected Closure $propertiesBuilder,
        protected Closure $uniqueIdBuilder,
    ) {
    }

    /**
     * Build the event's snake_case properties from the caller's payload.
     *
     * @return array<string, mixed>
     */
    public function properties(array $payloadData): array
    {
        return ($this->propertiesBuilder)($payloadData);
    }

    /**
     * Build the event's unique_id (idempotency key) from the caller's payload,
     * or null when this event has no stable dedupe key.
     */
    public function uniqueId(array $payloadData): ?string
    {
        return ($this->uniqueIdBuilder)($payloadData);
    }
}
