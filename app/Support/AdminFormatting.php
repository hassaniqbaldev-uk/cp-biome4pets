<?php

namespace App\Support;

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

    /** Derived test state → badge colour (reported = done, awaiting = pending). */
    public static function testStateColor(bool $hasReport): string
    {
        return $hasReport ? 'success' : 'warning';
    }

    /** Derived test state → human label. The stored status column was dropped; a
     *  test simply has a report or is awaiting one (see Test::hasReport()). */
    public static function testStateLabel(bool $hasReport): string
    {
        return $hasReport ? 'Reported' : 'Awaiting report';
    }
}
