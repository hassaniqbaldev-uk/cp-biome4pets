<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogProduct extends Model
{
    protected $fillable = [
        'name',
        'description',
        'url',
        'image_path',
        'price',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function triggerEntries(): HasMany
    {
        return $this->hasMany(CatalogProductTrigger::class);
    }

    public function getTriggerCodesAttribute(): array
    {
        return $this->triggerEntries->pluck('trigger')->all();
    }

    public function setTriggerCodesAttribute(array $codes): void
    {
        $this->triggerEntries()->delete();
        foreach ($codes as $code) {
            $this->triggerEntries()->create(['trigger' => $code]);
        }
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
