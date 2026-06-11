<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanStepProduct extends Model
{
    protected $fillable = [
        'plan_step_id',
        'catalog_product_id',
        'duration',
        'quantity',
        'dose',
        'inclusion',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(PlanStep::class, 'plan_step_id');
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
