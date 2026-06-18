<?php

namespace App\Support;

use App\Models\Test;

/**
 * Shared presentation constants/helpers for the admin panel, so date formats and
 * status-badge colours/labels are defined once and reused everywhere (no local
 * drift across resources, relation managers and dashboard widgets).
 *
 * Presentation only — no behaviour.
 */
class AdminFormatting
{
    /** Readable date for table columns / infolists, e.g. "18 Jun 2026". */
    public const DATE = 'd M Y';

    /** Date + time for the few places a timestamp genuinely matters. */
    public const DATE_TIME = 'd M Y, H:i';

    /** Report status → badge colour (published is the only "done" state). */
    public static function reportColor(?string $status): string
    {
        return match ($status) {
            'published' => 'success',
            default => 'gray',
        };
    }

    /** Report status → human label. */
    public static function reportLabel(?string $status): string
    {
        return ucfirst((string) $status);
    }

    /** Test status → badge colour (generated = done, results received = pending). */
    public static function testColor(?string $status): string
    {
        return match ($status) {
            'report_generated' => 'success',
            'results_received' => 'warning',
            default => 'gray',
        };
    }

    /** Test status → human label (from the Test model's canonical map). */
    public static function testLabel(?string $status): string
    {
        return Test::STATUSES[$status] ?? (string) $status;
    }
}
