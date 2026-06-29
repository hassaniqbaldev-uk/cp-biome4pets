<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conditional override layer on a base Plan, keyed by a pet condition. It stores
 * only the DELTAS from the base plan: an optional checkout-link override, optional
 * bundle-price overrides, and per-product swaps (its productOverrides). A plan with
 * no variants resolves to base — exactly today's behaviour.
 *
 * The condition vocabulary (CONDITION_*) is the single source of truth shared by
 * PlanVariantResolver and the future plan-builder UI, so resolver and UI never
 * drift on the keys.
 */
class PlanVariant extends Model
{
    /** Pet flagged sensitive (allergy/medication) — e.g. swap AMR → AMR Rosemary-Free. */
    public const CONDITION_SENSITIVE = 'sensitive';

    /** Pet flagged large-breed — e.g. different link + dosage (future). */
    public const CONDITION_LARGE = 'large';

    /** Pet flagged BOTH sensitive and large — its own dedicated variant. */
    public const CONDITION_SENSITIVE_LARGE = 'sensitive_large';

    /** All valid condition keys (for validation / UI option lists). */
    public const CONDITIONS = [
        self::CONDITION_SENSITIVE,
        self::CONDITION_LARGE,
        self::CONDITION_SENSITIVE_LARGE,
    ];

    /**
     * Human labels for each condition — the single source shared by the resolver
     * context, the plan-builder select/itemLabel and validation messages, so the
     * UI and back end never drift on wording.
     */
    public const CONDITION_LABELS = [
        self::CONDITION_SENSITIVE => 'Sensitive',
        self::CONDITION_LARGE => 'Large breed',
        self::CONDITION_SENSITIVE_LARGE => 'Sensitive + Large',
    ];

    protected $fillable = [
        'plan_id',
        'condition',
        'subscription_url',
        'subscription_price',
        'subscription_full_price',
        'subscription_billing_note',
        'subscription_saving_label',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function productOverrides(): HasMany
    {
        return $this->hasMany(PlanVariantProductOverride::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
