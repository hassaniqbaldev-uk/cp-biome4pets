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
        'subscription_discount_percent',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'subscription_discount_percent' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The price after the configured subscription discount, or null when there is
     * no price or no (positive) discount set — in which case the report shows the
     * plain price with no discount line. Rounded to whole pence.
     */
    public function discountedPrice(): ?float
    {
        if (is_null($this->price) || ! $this->hasSubscriptionDiscount()) {
            return null;
        }

        return round((float) $this->price * (100 - $this->subscription_discount_percent) / 100, 2);
    }

    /** Whether a positive subscription discount is configured for this product. */
    public function hasSubscriptionDiscount(): bool
    {
        return ! is_null($this->subscription_discount_percent)
            && $this->subscription_discount_percent > 0;
    }

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
