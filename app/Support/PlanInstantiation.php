<?php

namespace App\Support;

use App\Models\CatalogProduct;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\PlanStep;
use App\Models\PlanStepProduct;

/**
 * The SINGLE variant-aware seam that materialises a plan into a report: it turns a
 * Plan (+ the validated copy overlay) into the report's form-state steps and its
 * subscription_snapshot, applying the pet's conditional variant — product swaps,
 * dosage/quantity/duration overrides, the checkout-link override and a swap-aware
 * includes list — via PlanVariantResolver.
 *
 * Every place that instantiates a plan into a report MUST go through here so a
 * flagged pet can never be stranded on the base product. Today there is exactly one
 * such seam (the "Apply plan" action); createReportFromTest only sets plan_id and
 * regenerateReport only re-runs AI copy — neither writes plan products/snapshot, so
 * neither needs (or gets) instantiation. PlanVariantPathConsistencyTest locks this.
 *
 * INERT until variants are seeded (stage 6): with no pet flags or no defined
 * variant the resolver returns base, so the steps + snapshot are byte-identical to
 * before (a harmless additive snapshot['variant'] => null aside).
 */
class PlanInstantiation
{
    /**
     * @param  array  $copy  validated plan-copy overlay from ReportResource::validatePlanCopy()
     * @return array{steps: array<int,array>, subscription_snapshot: array<string,mixed>, variant: ?string, needs_review_reason: ?string}
     */
    public static function build(Plan $plan, ?Pet $pet, array $copy = []): array
    {
        $plan->loadMissing('steps.products.catalogProduct');

        // No pet (shouldn't normally happen at apply time) → base, no resolution.
        $resolution = $pet !== null
            ? PlanVariantResolver::resolve($plan, $pet)
            : self::baseResolution($plan);

        $swaps = $resolution['product_swaps'];

        $steps = $plan->steps->values()->map(function (PlanStep $step, int $i) use ($copy, $swaps): array {
            $isProse = $step->type === 'prose';
            $stepCopy = $copy['steps'][$i] ?? null;

            return [
                'type' => $step->type,
                'title' => $step->step_title,
                'stage_label' => $step->stage_label,
                'body' => $isProse ? ($stepCopy['body'] ?? $step->body) : null,
                'tip' => $isProse ? ($stepCopy['tip'] ?? $step->tip) : null,
                'products' => $isProse ? [] : $step->products->values()
                    ->map(fn (PlanStepProduct $p, int $j): array => self::product($p, $swaps, $stepCopy['products'][$j] ?? ''))
                    ->all(),
            ];
        })->all();

        return [
            'steps' => $steps,
            'subscription_snapshot' => self::snapshot($plan, $resolution, $swaps),
            'variant' => $resolution['condition_key'],
            'needs_review_reason' => $resolution['needs_review_reason'],
        ];
    }

    /**
     * One report-step product: identical to the base shape, except a swapped
     * product substitutes its catalogue id and any dose/quantity/duration override
     * (a null override inherits the base value, so non-swapped products are byte-
     * identical to before).
     */
    private static function product(PlanStepProduct $p, array $swaps, string $how): array
    {
        $swap = $swaps[(int) $p->catalog_product_id] ?? null;

        return [
            'catalog_product_id' => $swap !== null ? $swap['to_catalog_product_id'] : $p->catalog_product_id,
            'duration' => $swap !== null ? ($swap['duration'] ?? $p->duration) : $p->duration,
            'quantity' => $swap !== null ? ($swap['quantity'] ?? $p->quantity) : $p->quantity,
            'dose' => $swap !== null ? ($swap['dose'] ?? $p->dose) : $p->dose,
            'inclusion' => $p->inclusion,
            'how_it_helps' => $how !== '' ? $how : '[copy to be generated]',
        ];
    }

    /**
     * The frozen subscribe-panel snapshot. url is the resolved checkout link
     * (variant's, or base); variant records which condition resolved (null = base);
     * includes is recomputed so a swapped product shows under its new name.
     */
    private static function snapshot(Plan $plan, array $resolution, array $swaps): array
    {
        return [
            'available' => (bool) $plan->subscription_available,
            'price' => $plan->subscription_price,
            'full_price' => $plan->subscription_full_price,
            'billing_note' => $plan->subscription_billing_note,
            'saving_label' => $plan->subscription_saving_label,
            'url' => $resolution['checkout_url'],
            'variant' => $resolution['condition_key'],
            'includes' => self::includes($plan, $swaps),
        ];
    }

    /**
     * The {name, price} includes list, rebuilt with swaps applied. Starts from the
     * plan's curated subscription_includes (so the base case is byte-identical) and
     * substitutes any included product that a swap replaces — so "AMR Rosemary-Free"
     * shows instead of standard AMR rather than the wrong product slipping through.
     *
     * @return array<int,array{name:?string, price:mixed}>
     */
    private static function includes(Plan $plan, array $swaps): array
    {
        // from-product NAME => replacement {name, price}, for the active swaps.
        $byFromName = [];
        foreach ($swaps as $fromId => $swap) {
            $from = CatalogProduct::find($fromId);
            $to = CatalogProduct::find($swap['to_catalog_product_id']);
            if ($from && $to) {
                $byFromName[$from->name] = ['name' => $to->name, 'price' => $to->price];
            }
        }

        return collect($plan->subscription_includes ?? [])
            ->map(fn (string $name): array => $byFromName[$name]
                ?? ['name' => $name, 'price' => optional(CatalogProduct::where('name', $name)->first())->price])
            ->all();
    }

    /** Base resolution (no pet) — mirrors PlanVariantResolver's no-match result. */
    private static function baseResolution(Plan $plan): array
    {
        return [
            'variant' => null,
            'condition_key' => null,
            'checkout_url' => $plan->subscription_url,
            'product_swaps' => [],
            'needs_review_reason' => null,
        ];
    }
}
