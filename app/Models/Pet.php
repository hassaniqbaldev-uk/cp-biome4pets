<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Pet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'breed',
        'date_of_birth',
        'sex',
        'diet',
        'is_sensitive',
        'is_large_breed',
        'shopify_pet_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_sensitive' => 'boolean',
        'is_large_breed' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        // withTrashed: a pet whose client was soft-deleted (we don't cascade) must
        // still resolve its client's data for display, rather than appear ownerless.
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(Test::class);
    }

    /** A pet's dated health-notes log, most recent first. */
    public function healthNotes(): HasMany
    {
        return $this->hasMany(PetHealthNote::class)
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * Human age from the date of birth, e.g. "3 years 2 months". Null when no
     * DOB is on file. Used by the pet-hub header.
     */
    public function ageLabel(): ?string
    {
        if (blank($this->date_of_birth)) {
            return null;
        }

        return $this->date_of_birth->diffForHumans(Carbon::now(), [
            'syntax' => Carbon::DIFF_ABSOLUTE,
            'parts' => 2,
        ]);
    }

    /**
     * The most recent weight on record — the latest health-note entry that has a
     * weight (notes are date-desc). Null when no weight has ever been logged.
     */
    public function latestWeightKg(): ?float
    {
        $weight = $this->healthNotes()
            ->whereNotNull('weight_kg')
            ->value('weight_kg');

        return $weight !== null ? (float) $weight : null;
    }

    /**
     * The pet's health-notes history formatted for AI/report context (Part 2),
     * date-filtered to a point in time. Returns entries with date <= $asOf (all
     * entries when $asOf is null), CHRONOLOGICAL (oldest-first, so it reads as a
     * history), one line per entry as:
     *   "2026-01-10 · 7.20 kg · Started new kibble, occasional loose stools"
     * Missing pieces are omitted cleanly (weight-only or note-only render fine);
     * the date is always present. Returns null when no entries fall in range.
     *
     * This is owner-reported context only (not a clinical record) — the prompt
     * framing in OpenAiService is unchanged; it just receives a richer history.
     */
    public function healthNotesForContext(Carbon|string|null $asOf = null): ?string
    {
        $asOfDate = filled($asOf) ? Carbon::parse($asOf) : null;

        $notes = $this->healthNotes()
            ->reorder('date')
            ->orderBy('id')
            ->when($asOfDate, fn ($q) => $q->whereDate('date', '<=', $asOfDate))
            ->get();

        if ($notes->isEmpty()) {
            return null;
        }

        return $notes->map(function (PetHealthNote $n): string {
            $parts = [$n->date->format('Y-m-d')];

            if (filled($n->weight_kg)) {
                $parts[] = number_format((float) $n->weight_kg, 2) . ' kg';
            }
            if (filled($n->note)) {
                $parts[] = trim($n->note);
            }

            return implode(' · ', $parts);
        })->implode("\n");
    }
}
