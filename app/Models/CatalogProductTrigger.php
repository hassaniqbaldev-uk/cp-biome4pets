<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogProductTrigger extends Model
{
    protected $table = 'catalog_product_trigger';

    protected $fillable = [
        'catalog_product_id',
        'trigger',
    ];

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
