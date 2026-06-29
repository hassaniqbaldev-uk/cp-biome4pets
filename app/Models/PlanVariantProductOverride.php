<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product swap inside a PlanVariant: replace the base catalogue product
 * (from_catalog_product_id) with another (to_catalog_product_id), optionally
 * overriding the dose / quantity / duration. A null override field inherits the
 * base plan_step_product's value. The replacement product's own name / price /
 * url come from its catalogue row, so a swap is enough to change the displayed
 * product without storing those here.
 */
class PlanVariantProductOverride extends Model
{
    protected $fillable = [
        'plan_variant_id',
        'from_catalog_product_id',
        'to_catalog_product_id',
        'dose',
        'quantity',
        'duration',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PlanVariant::class, 'plan_variant_id');
    }

    public function fromCatalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'from_catalog_product_id');
    }

    public function toCatalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'to_catalog_product_id');
    }
}
