<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportStep extends Model
{
    protected $fillable = [
        'report_id',
        'title',
        'description',
        'type',
        'stage_label',
        'body',
        'tip',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ReportStepProduct::class)->orderBy('position');
    }
}
