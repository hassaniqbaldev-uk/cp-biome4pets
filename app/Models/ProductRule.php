<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductRule extends Model
{
    protected $fillable = [
        'trigger_name',
        'metric',
        'operator',
        'value',
        'value2',
        'is_active',
    ];

    protected $casts = [
        'value' => 'float',
        'value2' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Supported operators. 'outside' covers the "above X or below Y" case in
     * a single row; 'between' is its inclusive inverse.
     */
    public const OPERATORS = [
        'gt' => 'Greater than (>)',
        'lt' => 'Less than (<)',
        'gte' => 'Greater than or equal (≥)',
        'lte' => 'Less than or equal (≤)',
        'between' => 'Between [value, value2] (inclusive)',
        'outside' => 'Outside [value, value2] (< value or > value2)',
    ];

    /**
     * Metrics that exist in report data and can be compared against.
     */
    public const METRICS = [
        'Bacteroidetes' => 'Bacteroidetes (%)',
        'Firmicutes' => 'Firmicutes (%)',
        'Proteobacteria' => 'Proteobacteria (%)',
        'Fusobacteria' => 'Fusobacteria (%)',
        'diversity_score' => 'Shannon Diversity Score',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Evaluate this rule against a metric value, preserving the original
     * strict-inequality semantics ('outside' = < value OR > value2).
     */
    public function matches(float $metricValue): bool
    {
        $value = (float) $this->value;
        $value2 = $this->value2 !== null ? (float) $this->value2 : null;

        return match ($this->operator) {
            'gt' => $metricValue > $value,
            'lt' => $metricValue < $value,
            'gte' => $metricValue >= $value,
            'lte' => $metricValue <= $value,
            'between' => $value2 !== null && $metricValue >= $value && $metricValue <= $value2,
            'outside' => $value2 !== null && ($metricValue < $value || $metricValue > $value2),
            default => false,
        };
    }

    /**
     * Distinct trigger names defined by the rules, for use as a shared,
     * dynamic trigger list (e.g. on the CatalogProduct form). Falls back to
     * the original five if the table is empty/unreadable.
     */
    public static function triggerNameOptions(): array
    {
        try {
            $names = static::query()
                ->select('trigger_name')
                ->distinct()
                ->orderBy('trigger_name')
                ->pluck('trigger_name')
                ->all();
        } catch (\Throwable) {
            $names = [];
        }

        if (empty($names)) {
            $names = ['AMR', 'Prebiotic', 'Biotic Boost', 'Antimicrobic', 'FMT'];
        }

        return array_combine($names, $names);
    }
}
