<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\BulkRegenerateReports;
use App\Filament\Resources\ReportResource;
use App\Models\BulkRegenerateRun;
use Filament\Widgets\Widget;

/**
 * Dashboard card for the current Super Admin's most recent bulk-regeneration run,
 * so a closed tab doesn't lose all visibility:
 *   - COMPLETED (unacknowledged) → success card + needs-review link, dismissible.
 *   - INTERRUPTED (running + stale heartbeat) → warning card + Resume / Cancel.
 *   - otherwise → no card.
 * Super Admins only (it's their tool).
 */
class BulkRegenerateRunCard extends Widget
{
    protected static string $view = 'filament.widgets.bulk-regenerate-run-card';

    protected int|string|array $columnSpan = 'full';

    // Surface it at the very top of the dashboard.
    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** The run to surface (status materialised if a stale 'running' row), or null. */
    public function getRun(): ?BulkRegenerateRun
    {
        return BulkRegenerateRun::dashboardCardFor((int) auth()->id());
    }

    /** Dismiss a completed run's card (mark acknowledged) so it stops nagging. */
    public function acknowledge(): void
    {
        $run = $this->getRun();
        if ($run && $run->status === BulkRegenerateRun::STATUS_COMPLETED) {
            $run->update(['acknowledged_at' => now()]);
        }
    }

    /** Cancel an interrupted run the admin doesn't want to resume → clears the card. */
    public function cancel(): void
    {
        $run = $this->getRun();
        if ($run && $run->status === BulkRegenerateRun::STATUS_INTERRUPTED) {
            $run->update(['status' => BulkRegenerateRun::STATUS_CANCELLED]);
        }
    }

    /** Reopen the bulk tool and continue the interrupted run from its remaining ids. */
    public function resumeUrl(?BulkRegenerateRun $run): ?string
    {
        return $run ? BulkRegenerateReports::getUrl(['resume' => $run->id]) : null;
    }

    public function needsReviewUrl(): string
    {
        return ReportResource::getUrl('index', ['tableFilters[needs_review][value]' => '1']);
    }
}
