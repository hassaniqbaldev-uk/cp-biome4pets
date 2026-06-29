<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'key',
        'name',
        'trigger_description',
        'enabled',
        'is_fallback',
        'match_priority',
        'species_availability',
        'intro_guidance',
        'position',
        'subscription_available',
        'subscription_price',
        'subscription_full_price',
        'subscription_billing_note',
        'subscription_includes',
        'subscription_url',
        'subscription_saving_label',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_fallback' => 'boolean',
        'match_priority' => 'integer',
        'subscription_available' => 'boolean',
        'subscription_includes' => 'array',
        'position' => 'integer',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(PlanStep::class)->orderBy('position');
    }

    /**
     * The trigger-set conditions that auto-recommend this plan. Each row is an
     * AND-set; rows are OR-ed. Ordered by position for tidy display/editing.
     */
    public function triggerConditions(): HasMany
    {
        return $this->hasMany(PlanTriggerCondition::class)->orderBy('position');
    }

    /**
     * Conditional variants (override layers keyed by pet condition). A plan with no
     * variants resolves to base — see PlanVariantResolver. Inert until later stages
     * wire it into generation/checkout.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(PlanVariant::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Build the fixed plan scaffold (plan-generation-prompt.md §3 shape) used as
     * the generator input AND the validation oracle. Factual fields (name, price,
     * dose, duration, quantity, product_url, inclusion) come straight from the
     * catalogue/plan; copy fields are left empty for the model to fill.
     */
    public function toScaffold(?string $petName = null): array
    {
        $this->loadMissing('steps.products.catalogProduct');

        return [
            'plan_id' => $this->key,
            'plan_name' => $this->name,
            'pet_name' => $petName ?? '',
            'intro' => '',
            'subscription' => [
                'available' => (bool) $this->subscription_available,
                'price' => $this->subscription_price,
                'billing_note' => $this->subscription_billing_note,
                'includes' => $this->subscription_includes ?? [],
            ],
            'steps' => $this->steps->map(function (PlanStep $step) {
                if ($step->type === 'prose') {
                    return [
                        'type' => 'prose',
                        'step_title' => $step->step_title,
                        'stage_label' => $step->stage_label,
                        'body' => '',
                        'tip' => '',
                    ];
                }

                return [
                    'type' => 'product',
                    'step_title' => $step->step_title,
                    'stage_label' => $step->stage_label,
                    'products' => $step->products->map(fn (PlanStepProduct $product) => [
                        'name' => $product->catalogProduct?->name,
                        'price' => self::formatPrice($product->catalogProduct?->price),
                        'dose' => $product->dose,
                        'duration' => $product->duration,
                        'quantity' => $product->quantity,
                        'how_it_helps' => '',
                        'product_url' => $product->catalogProduct?->url,
                        'inclusion' => $product->inclusion,
                    ])->all(),
                ];
            })->all(),
        ];
    }

    protected static function formatPrice($price): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }

        return '£' . number_format((float) $price, 2);
    }
}
