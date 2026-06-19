<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AND-set of triggers that selects a Plan: ALL trigger names in
 * required_triggers must have fired for this row to match. Multiple rows on a
 * plan are OR-ed (any satisfied row selects the plan). See the matcher in
 * ReportResource::recommendPlanId().
 */
class PlanTriggerCondition extends Model
{
    protected $fillable = [
        'plan_id',
        'position',
        'required_triggers',
    ];

    protected $casts = [
        'required_triggers' => 'array',
        'position' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
