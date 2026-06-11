<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanStep extends Model
{
    protected $fillable = [
        'plan_id',
        'type',
        'step_title',
        'stage_label',
        'body',
        'tip',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(PlanStepProduct::class)->orderBy('position');
    }
}
