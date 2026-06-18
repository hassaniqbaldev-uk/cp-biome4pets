<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated entry in a pet's health-notes log: a free-text note and/or a weight
 * reading. An entry must carry at least one of the two — a wholly empty entry is
 * meaningless and is rejected at the model level (and in the Filament form).
 */
class PetHealthNote extends Model
{
    protected $fillable = [
        'date',
        'note',
        'weight_kg',
    ];

    protected $casts = [
        'date' => 'date',
        'weight_kg' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Model-level guard: an entry needs a note or a weight (weight 0 counts as
        // present). Mirrors the form rule so neither path can persist an empty row.
        static::saving(function (PetHealthNote $note): void {
            if (blank($note->note) && blank($note->weight_kg)) {
                throw new \InvalidArgumentException(
                    'A health note must have at least a note or a weight.'
                );
            }
        });
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
