<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportStepProduct extends Model
{
    protected $fillable = [
        'report_step_id',
        'catalog_product_id',
        'duration',
        'quantity',
        'dose',
        'inclusion',
        'how_it_helps',
        'position',
    ];

    protected $casts = [
        // quantity is now a string (e.g. "3 (one tub per month)").
        'position' => 'integer',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(ReportStep::class, 'report_step_id');
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
