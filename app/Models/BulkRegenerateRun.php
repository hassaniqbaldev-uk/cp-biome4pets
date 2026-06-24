<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bulk-regeneration run, persisted so it survives a closed tab. The in-browser
 * chunked processor updates this row every chunk (heartbeat + progress). A run is
 * inferred INTERRUPTED when it is still 'running' but its heartbeat is stale (the
 * browser stopped polling), so we never need the dead tab to announce itself.
 */
class BulkRegenerateRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INTERRUPTED = 'interrupted';

    public const STATUS_CANCELLED = 'cancelled';

    /** A 'running' row with no chunk this long is treated as interrupted. */
    public const STALE_AFTER_MINUTES = 2;

    protected $fillable = [
        'started_by', 'total', 'batch_ids', 'remaining_ids',
        'regenerated_count', 'failed_count', 'needs_review_count',
        'status', 'last_progress_at', 'started_at', 'finished_at', 'acknowledged_at',
    ];

    protected $casts = [
        'batch_ids' => 'array',
        'remaining_ids' => 'array',
        'total' => 'integer',
        'regenerated_count' => 'integer',
        'failed_count' => 'integer',
        'needs_review_count' => 'integer',
        'last_progress_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** Reports already processed (the full batch minus what's left). */
    public function doneCount(): int
    {
        return max(0, $this->total - $this->remainingCount());
    }

    public function remainingCount(): int
    {
        return count($this->remaining_ids ?? []);
    }

    /**
     * A run whose browser stopped driving it: still 'running' but no chunk has
     * landed within STALE_AFTER_MINUTES. Inferred from the heartbeat — the dead
     * tab never has to report in.
     */
    public function isStale(): bool
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return false;
        }

        $heartbeat = $this->last_progress_at ?? $this->started_at ?? $this->created_at;

        return $heartbeat === null
            || $heartbeat->lt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * Persist 'interrupted' lazily when a stale 'running' row is read, so the
     * dashboard/anything that looks at it sees the real state. Returns $this.
     */
    public function markInterruptedIfStale(): self
    {
        if ($this->isStale()) {
            $this->update([
                'status' => self::STATUS_INTERRUPTED,
                'finished_at' => $this->finished_at ?? Carbon::now(),
            ]);
        }

        return $this;
    }

    /**
     * The single run (if any) to surface on a user's dashboard: their most recent
     * run that is either an unacknowledged COMPLETED run or an INTERRUPTED one (a
     * still-'running' row is materialised to interrupted here when stale). A fresh
     * running row (a tab actively processing) and acknowledged/cancelled runs
     * yield no card.
     */
    public static function dashboardCardFor(int $userId): ?self
    {
        $run = static::query()
            ->where('started_by', $userId)
            ->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_RUNNING, self::STATUS_INTERRUPTED])
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        // Materialise a stale 'running' row to 'interrupted' so the card is right.
        $run->markInterruptedIfStale();

        if ($run->status === self::STATUS_COMPLETED && $run->acknowledged_at === null) {
            return $run;
        }

        if ($run->status === self::STATUS_INTERRUPTED) {
            return $run;
        }

        return null; // fresh-running (live in a tab), acknowledged, or cancelled
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('started_by', $userId);
    }
}
