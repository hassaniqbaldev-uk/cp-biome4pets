<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Wires a Filament resource for soft deletes: the base query drops the
 * SoftDeletingScope so the table's TrashedFilter can show/hide/restore trashed
 * rows (and the edit page can resolve a trashed record to restore it). The
 * TrashedFilter still hides trashed rows by DEFAULT, so normal lists are
 * unchanged. Pair this with TrashedFilter + a Restore action in table(). Note:
 * force-delete (permanent) is deliberately NOT exposed in the UI for any role —
 * the panel only ever soft-deletes (recoverable); permanent removal is a
 * DB-level operation only. This is the safest posture for "never lose data".
 */
trait SoftDeletableResource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
