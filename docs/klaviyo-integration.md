# Klaviyo Integration — Event Reference

A guide for building Klaviyo flows and templates against the events this app
sends. For the Klaviyo expert: you can wire flows off the **metric name** and use
the **event properties** below as template variables.

## Overview

- **Server-side integration** using Klaviyo's **Create Event** API:
  `POST {base_url}/api/events/`.
- **API revision:** `2026-04-15` (configurable — see [Configuration](#configuration)).
- **Manual send only.** An admin opens a report in the portal and clicks
  **Send Report**. **Nothing fires automatically** — not on publish, not on a
  schedule, not via any background job. Each event is an explicit human action.
- Events are sent with a stable `unique_id`, so **re-sending the same report
  de-duplicates** in Klaviyo (it will not create a second event).
- There is also a **Send test event** button in settings for dry-runs (see
  [Testing](#testing)).

## Registered events

### `report_published`

| | |
|---|---|
| **Event key** (internal) | `report_published` |
| **Klaviyo metric name** | `Report Published` |
| **`unique_id` scheme** | `report_published_{report_id}` — stable per report; re-sends de-duplicate |

**Event properties:**

| Property (snake_case) | Description |
|---|---|
| `pet_name` | The pet's name (e.g. "Biscuit"). |
| `report_url` | Absolute URL to the public report page (`/report/{slug}`). |
| `report_date` | The report's date, formatted for display (e.g. "June 15, 2026"). |
| `client_name` | The pet owner's / client's name (e.g. "Jane Owner"). |

> Build the email/flow on the **`Report Published`** metric. The four properties
> above are available as event variables in your template.

## Sample payload

This is the actual request body the service sends for a `report_published`
event (JSON:API format, profile and metric use the nested `data` wrapper
required by revision 2026-04-15):

```json
{
  "data": {
    "type": "event",
    "attributes": {
      "profile": {
        "data": {
          "type": "profile",
          "attributes": { "email": "owner@example.com" }
        }
      },
      "metric": {
        "data": {
          "type": "metric",
          "attributes": { "name": "Report Published" }
        }
      },
      "properties": {
        "pet_name": "Biscuit",
        "report_url": "https://portal.example.com/report/biscuit-8b3342",
        "report_date": "June 15, 2026",
        "client_name": "Jane Owner"
      },
      "time": "2026-06-15T10:00:00+00:00",
      "unique_id": "report_published_42"
    }
  }
}
```

A successful submission returns **HTTP 202 Accepted**.

## Adding a new event later

The event list is an extensible registry. To add a new event, add **one entry**
to `EventRegistry::definitions()` in
`app/Services/Klaviyo/EventRegistry.php` — the metric name, a property builder,
and a `unique_id` builder. **No changes to the service are needed.** For example:

```php
'order_shipped' => new EventDefinition(
    metric: 'Order Shipped',
    propertiesBuilder: fn (array $data): array => [
        'order_number' => $data['order_number'] ?? null,
        'tracking_url' => $data['tracking_url'] ?? null,
    ],
    uniqueIdBuilder: fn (array $data): ?string => isset($data['order_id'])
        ? 'order_shipped_'.$data['order_id']
        : null,
),
```

Once added, document the new metric name and properties in the table above so
the Klaviyo side stays in sync.

## Configuration

All settings live in the admin panel under **System → Email & Integrations →
Klaviyo**:

| Setting | Purpose |
|---|---|
| **Private API Key** | Klaviyo private key. Stored encrypted; sent as `Authorization: Klaviyo-API-Key <key>`. |
| **Enabled** | Master switch. When off, no events are sent (the Send Report action is disabled). |
| **API Revision** | The `revision` header. Default `2026-04-15`. |
| **Base URL** | Klaviyo API base URL. Default `https://a.klaviyo.com`. |

## Testing

On the **Email & Integrations → Klaviyo** screen:

- **Test connection** — calls `GET {base_url}/api/accounts/` with the saved key
  and shows the connected account name. Use this to confirm the key works.
- **Send test event** — sends a sample `Report Published` event to an email you
  enter, with dummy data, so you can confirm it lands in Klaviyo (Profiles →
  that email → Activity) before any template exists.

Both buttons use the **saved** settings — if you change the key, **Save first**.
The screen also shows the **last result** (timestamp + OK/Failed + message), and
each report shows its own **last sent to Klaviyo** status.
