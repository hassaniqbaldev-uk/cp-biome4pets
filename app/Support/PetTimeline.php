<?php

namespace App\Support;

use App\Filament\Resources\PetResource;
use App\Filament\Resources\ReportResource;
use App\Models\Pet;
use App\Models\PetHealthNote;
use App\Models\Report;
use App\Models\Test;
use Illuminate\Support\Collection;

/**
 * Step 4: the per-pet history timeline — a merged, newest-first feed of the pet's
 * Tests, Reports and Health-note entries. Pure and read-only: it assembles event
 * rows (type, date, summary, out-links) for display; creating/editing still
 * happens in the Tests and Health Notes relation managers. Eager-loads the three
 * relations once and merges in PHP (per-pet volumes are small) — no per-row queries.
 *
 * Each event is an array:
 *   type, type_label, color, icon, date (Carbon|null), title, summary,
 *   links[] => [label, url, newTab]
 */
class PetTimeline
{
    public const TYPES = [
        'test' => 'Tests',
        'report' => 'Reports',
        'note' => 'Health notes',
    ];

    /**
     * @param  string|null  $type  one of test|report|note, or null for all
     * @param  string|null  $from  inclusive lower bound (Y-m-d)
     * @param  string|null  $to    inclusive upper bound (Y-m-d)
     */
    public static function build(Pet $pet, ?string $type = null, ?string $from = null, ?string $to = null): Collection
    {
        // Bounded eager-load so the merge never N+1s (reports.test for the
        // "from test …" summary + report date proxy).
        $pet->loadMissing(['tests', 'reports.test', 'healthNotes']);

        $events = collect();

        if ($type === null || $type === 'test') {
            foreach ($pet->tests as $test) {
                $events->push(self::testEvent($test, $pet));
            }
        }

        if ($type === null || $type === 'report') {
            foreach ($pet->reports as $report) {
                $events->push(self::reportEvent($report));
            }
        }

        if ($type === null || $type === 'note') {
            foreach ($pet->healthNotes as $note) {
                $events->push(self::noteEvent($note));
            }
        }

        // Date-range filter (inclusive), compared by calendar date.
        if (filled($from)) {
            $events = $events->filter(fn (array $e) => $e['date'] && $e['date']->toDateString() >= $from);
        }
        if (filled($to)) {
            $events = $events->filter(fn (array $e) => $e['date'] && $e['date']->toDateString() <= $to);
        }

        // Newest-first.
        return $events
            ->sortByDesc(fn (array $e) => $e['date']?->timestamp ?? 0)
            ->values();
    }

    protected static function testEvent(Test $test, Pet $pet): array
    {
        $date = $test->report_date ?? $test->collected_at ?? $test->created_at;

        $summary = array_filter([
            $test->microbiome_classification,
            $test->diversity_score !== null ? 'diversity ' . number_format((float) $test->diversity_score, 2) : null,
        ]);

        return [
            'type' => 'test',
            'type_label' => 'Test',
            'color' => 'info',
            'icon' => 'heroicon-o-beaker',
            'date' => $date,
            'title' => 'Test ' . ($test->order_id ?? $test->getKey()),
            'summary' => $summary ? implode(' · ', $summary) : 'Awaiting results',
            // No URL-addressable test view in Filament 3.2 (it's a relation-manager
            // modal) — link back to the pet hub where the Tests section lives.
            'links' => [
                ['label' => 'Open in Tests', 'url' => PetResource::getUrl('edit', ['record' => $pet]), 'newTab' => false],
            ],
        ];
    }

    protected static function reportEvent(Report $report): array
    {
        $date = $report->report_date ?? $report->created_at;
        $fromTest = $report->test?->order_id;

        $links = [
            ['label' => 'Edit report', 'url' => ReportResource::getUrl('edit', ['record' => $report]), 'newTab' => false],
        ];
        if (filled($report->slug)) {
            $links[] = ['label' => 'View report', 'url' => $report->report_url, 'newTab' => true];
        }

        return [
            'type' => 'report',
            'type_label' => 'Report',
            'color' => 'success',
            'icon' => 'heroicon-o-document-chart-bar',
            'date' => $date,
            'title' => 'Report',
            'summary' => 'Status: ' . ucfirst($report->status ?? 'draft')
                . ($fromTest ? ' · from test ' . $fromTest : ''),
            'links' => $links,
        ];
    }

    protected static function noteEvent(PetHealthNote $note): array
    {
        $summary = array_filter([
            filled($note->weight_kg) ? number_format((float) $note->weight_kg, 2) . ' kg' : null,
            filled($note->note) ? trim($note->note) : null,
        ]);

        return [
            'type' => 'note',
            'type_label' => 'Health note',
            'color' => 'warning',
            'icon' => 'heroicon-o-clipboard-document-list',
            'date' => $note->date,
            'title' => 'Health note',
            'summary' => implode(' · ', $summary),
            'links' => [],
        ];
    }
}
