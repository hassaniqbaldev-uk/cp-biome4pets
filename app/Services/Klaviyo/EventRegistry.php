<?php

namespace App\Services\Klaviyo;

/**
 * The extensible Klaviyo event registry.
 *
 * Maps an internal event key to its EventDefinition (metric name + property
 * builder + unique_id builder). This is the future-proofing seam: adding a new
 * Klaviyo event is a single entry in definitions() — KlaviyoService never
 * changes.
 */
class EventRegistry
{
    /**
     * Internal event key => EventDefinition.
     *
     * @return array<string, EventDefinition>
     */
    public static function definitions(): array
    {
        return [
            // A report has been published / shared with the client.
            //
            // unique_id is Klaviyo's idempotency key: a repeat event with the same
            // unique_id is DROPPED (no email re-sent). So it is deliberately keyed on
            // report_id + the send time, NOT report_id alone — a stable-per-report id
            // silently swallowed every deliberate re-send. With the send time mixed in,
            // each re-send is a DISTINCT event Klaviyo will deliver, while two sends in
            // the SAME second still collapse to one (a backstop against an accidental
            // double-fire). Falls back to the per-report id when no time is supplied
            // (e.g. a caller that wants the old dedupe-once behaviour).
            'report_published' => new EventDefinition(
                metric: 'Report Published',
                propertiesBuilder: fn (array $data): array => [
                    'pet_name' => $data['pet_name'] ?? null,
                    'report_url' => $data['report_url'] ?? null,
                    'report_date' => $data['report_date'] ?? null,
                    'client_name' => $data['client_name'] ?? null,
                ],
                uniqueIdBuilder: fn (array $data): ?string => isset($data['report_id'])
                    ? 'report_published_'.$data['report_id'].(isset($data['time']) ? '_'.$data['time'] : '')
                    : null,
            ),
        ];
    }

    /**
     * Resolve a definition by its internal event key, or null when unknown.
     */
    public static function get(string $eventKey): ?EventDefinition
    {
        return static::definitions()[$eventKey] ?? null;
    }

    /**
     * All registered event keys (handy for diagnostics / future UI).
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(static::definitions());
    }
}
