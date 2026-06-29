<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\PlanVariant;
use App\Models\Pet;

/**
 * Resolves which plan variant (if any) applies to a pet, from the pet's condition
 * flags. PURE and side-effect-free: it only reads the plan's enabled variants and
 * the pet's flags and returns a verdict — it never mutates a plan, pet, report or
 * variant. Stage 2 of the conditional-variant system; NOT wired into generation /
 * checkout / UI yet, so by itself it changes no live behaviour.
 *
 * Resolution (most-specific-first, never merge two variants):
 *   - Build candidate condition keys from the flags:
 *       both flags  → [sensitive_large, sensitive, large]   (sensitive precedes
 *                      large: it's an allergy/safety concern, so it wins a tie)
 *       sensitive   → [sensitive]
 *       large       → [large]
 *       neither     → []  (base)
 *   - Pick the FIRST candidate that has an ENABLED variant on this plan; use that
 *     variant wholesale (its link + its swaps + its overrides).
 *   - No candidate matches → base (variant null, base checkout url, no swaps).
 *
 * Combined-gap guard: a pet flagged BOTH but resolved to anything other than the
 * dedicated 'sensitive_large' variant (a single-axis variant, or base) yields a
 * deterministic needs_review_reason so staff can confirm the link/dosage. This
 * drives needs_review once wired in a later stage.
 */
class PlanVariantResolver
{
    /**
     * @return array{
     *     variant: PlanVariant|null,
     *     condition_key: string|null,
     *     checkout_url: string|null,
     *     product_swaps: array<int, array{to_catalog_product_id:int, dose:?string, quantity:?string, duration:?string}>,
     *     needs_review_reason: string|null
     * }
     */
    public static function resolve(Plan $plan, Pet $pet): array
    {
        $sensitive = (bool) $pet->is_sensitive;
        $large = (bool) $pet->is_large_breed;

        $candidates = self::candidateConditions($sensitive, $large);

        // Enabled variants for this plan, keyed by condition. Queried fresh (read
        // only) so the result never depends on what happens to be eager-loaded.
        $variants = $plan->variants()
            ->enabled()
            ->with('productOverrides')
            ->get()
            ->keyBy('condition');

        $chosen = null;
        $chosenKey = null;
        foreach ($candidates as $key) {
            if ($variants->has($key)) {
                $chosen = $variants->get($key);
                $chosenKey = $key;
                break;
            }
        }

        return [
            'variant' => $chosen,
            'condition_key' => $chosenKey,
            // Variant's link override wins; otherwise inherit the base plan's link.
            'checkout_url' => $chosen?->subscription_url ?? $plan->subscription_url,
            'product_swaps' => $chosen ? self::buildSwaps($chosen) : [],
            'needs_review_reason' => self::reviewReason($sensitive, $large, $chosenKey),
        ];
    }

    /**
     * Candidate condition keys, most-specific first. Sensitive precedes large so
     * that a both-flagged pet with only single-axis variants resolves to sensitive.
     *
     * @return list<string>
     */
    private static function candidateConditions(bool $sensitive, bool $large): array
    {
        if ($sensitive && $large) {
            return [
                PlanVariant::CONDITION_SENSITIVE_LARGE,
                PlanVariant::CONDITION_SENSITIVE,
                PlanVariant::CONDITION_LARGE,
            ];
        }

        if ($sensitive) {
            return [PlanVariant::CONDITION_SENSITIVE];
        }

        if ($large) {
            return [PlanVariant::CONDITION_LARGE];
        }

        return [];
    }

    /**
     * The from→to swap map for the chosen variant.
     *
     * @return array<int, array{to_catalog_product_id:int, dose:?string, quantity:?string, duration:?string}>
     */
    private static function buildSwaps(PlanVariant $variant): array
    {
        $swaps = [];

        foreach ($variant->productOverrides as $override) {
            $swaps[(int) $override->from_catalog_product_id] = [
                'to_catalog_product_id' => (int) $override->to_catalog_product_id,
                'dose' => $override->dose,
                'quantity' => $override->quantity,
                'duration' => $override->duration,
            ];
        }

        return $swaps;
    }

    /**
     * The combined-gap review reason, or null. Fires only for a pet flagged BOTH
     * sensitive AND large that did NOT resolve to the dedicated 'sensitive_large'
     * variant — i.e. it fell back to a single-axis variant or to base.
     */
    private static function reviewReason(bool $sensitive, bool $large, ?string $chosenKey): ?string
    {
        if (! ($sensitive && $large)) {
            return null;
        }

        if ($chosenKey === PlanVariant::CONDITION_SENSITIVE_LARGE) {
            return null;
        }

        if ($chosenKey === null) {
            return 'Sensitive + large-breed pet, but no matching plan variant is defined — '
                .'used the base plan. Please confirm the checkout link and dosage are correct.';
        }

        return 'Sensitive + large-breed pet, but no combined plan variant is defined — '
            ."used the {$chosenKey} variant. Please confirm the checkout link and dosage are correct.";
    }
}
