<?php

namespace App\Support;

use App\Models\CatalogProduct;
use App\Models\Report;

/**
 * The customer-facing Boil & Broth bone broth recommendation block.
 *
 * Single source of truth for BOTH the web report and the PDF: the fixed partner
 * wording, the flavour-driven recommendation line, the visibility gate, and the two
 * products' own data (url, price, image) — which now live in the product CATALOGUE,
 * not in Settings. The templates call forReport() and render only when it returns a
 * non-null payload, so web and PDF can never disagree about WHEN the block shows.
 *
 * The two products are found by a MARKER column on catalog_products
 * (bone_broth_flavour = 'chicken' | 'beef'), NOT by name — one source of truth that
 * the future Clostridia plan can reuse. This is DISTINCT from reports.bone_broth_flavour
 * (the AI's per-dog PICK), which is unchanged and still drives the recommendation line
 * and the switcher's preselected tab.
 *
 * Visibility — the block shows ONLY when ALL hold:
 *   1. the dog's dysbiosis is Low (the banded state, ReportContent::dysbiosisBand),
 *   2. the report's plan/products do NOT already include a bone broth product (detected
 *      generically by the marker: any plan-step product or attached catalog product
 *      with a non-null bone_broth_flavour), AND
 *   3. the RELEVANT flavour's catalog product exists and has a url (chicken pick →
 *      chicken product; beef → beef; either → at least one). A flavour with no
 *      product/url shows no button; neither present → the block does not render.
 *
 * No AI call, no plan/rule change — pure read-only presentation.
 */
class BoneBroth
{
    /** The fixed Boil & Broth partner wording — verbatim, identical on web + PDF. */
    public const WORDING = 'Bone broth is a natural, nutrient-rich addition to a pet\'s diet, providing collagen, gelatin and essential amino acids that may help support digestive health, gut lining integrity and overall wellbeing. Emerging research also suggests that regular bone broth consumption may positively influence the gut microbiome, encouraging beneficial bacteria and microbial diversity. Biome4Pets has chosen Boil & Broth as its trusted partner because of its commitment to producing high-quality, 100% natural bone broths using responsibly sourced British ingredients, combined with a shared passion for science-led pet nutrition. Our collaborative canine microbiome study with Aberystwyth University, involving 33 dogs, found encouraging improvements in bacterial diversity after just four weeks of supplementation. This evidence-led approach makes Boil & Broth a natural partner in our mission to help pets achieve healthier, more balanced gut microbiomes.';

    /**
     * Whether the dog's dysbiosis is in the banded "Low" state — the same band the
     * report already prints (ReportContent::dysbiosisBand), so this gate moves with
     * the band thresholds and never hard-codes a number.
     */
    public static function dysbiosisIsLow(Report $report): bool
    {
        return ReportContent::dysbiosisBand((float) ($report->dysbiosis_score ?? 0))['label'] === 'Low';
    }

    /**
     * The active catalog product marked as the given flavour's bone broth ('chicken'
     * or 'beef'), or null when none exists. If two share a marker, the first by id wins
     * (deterministic). Only active products are eligible.
     */
    public static function product(string $flavour): ?CatalogProduct
    {
        return CatalogProduct::query()
            ->where('is_active', true)
            ->where('bone_broth_flavour', $flavour)
            ->orderBy('id')
            ->first();
    }

    /**
     * Generic "is a bone broth product already in this report's plan/products?" check,
     * now by MARKER: any plan-step product OR directly-attached catalog product whose
     * bone_broth_flavour is non-null. Works the moment a bone broth product is added to
     * a plan, with no reliance on names/URLs. With none anywhere today it returns false.
     */
    public static function planIncludesBoneBroth(Report $report): bool
    {
        foreach ($report->steps as $step) {
            foreach ($step->products as $product) {
                if (filled($product->catalogProduct?->bone_broth_flavour)) {
                    return true;
                }
            }
        }

        foreach ($report->catalogProducts as $catalog) {
            if (filled($catalog->bone_broth_flavour)) {
                return true;
            }
        }

        return false;
    }

    /** The recommendation line for a flavour + pet name (chicken/beef vs neutral "either"). */
    public static function recommendationLine(string $flavour, string $petName): string
    {
        $tail = 'For the entire 6 month programme. Please only feed one week after introducing the supplements.';

        if ($flavour === 'chicken' || $flavour === 'beef') {
            return "We recommend {$flavour} for {$petName}. {$tail}";
        }

        return "Chicken and beef both suit {$petName}. Choose whichever your dog prefers. {$tail}";
    }

    /**
     * The display payload for one flavour's catalog product, or null when the product
     * is missing OR has no url (a buyable product only). Price is the product's own
     * price formatted to 2dp, or null when unpriced.
     *
     * @return array{url: string, price: ?string, image: ?string}|null
     */
    private static function flavourData(string $flavour): ?array
    {
        $product = self::product($flavour);
        $url = trim((string) ($product?->url ?? ''));
        if ($product === null || $url === '') {
            return null;
        }

        return [
            'url' => $url,
            'price' => $product->price !== null ? number_format((float) $product->price, 2) : null,
            'image' => filled($product->image_path) ? (string) $product->image_path : null,
        ];
    }

    /**
     * Assemble the bone broth block for a report, or NULL when it must not render.
     *
     * @return array{
     *   flavour: string, selected: ?string, pet_name: string, recommendation: string,
     *   wording: string, chicken: array{url:string,price:?string,image:?string}|null,
     *   beef: array{url:string,price:?string,image:?string}|null
     * }|null
     */
    public static function forReport(Report $report): ?array
    {
        // Gate 1: dysbiosis must be Low.
        if (! self::dysbiosisIsLow($report)) {
            return null;
        }

        // Gate 2: never show alongside a plan that already includes bone broth.
        if (self::planIncludesBoneBroth($report)) {
            return null;
        }

        // Normalise the stored PICK to a known value (null/legacy → neutral "either").
        $flavour = in_array($report->bone_broth_flavour, ['chicken', 'beef', 'either'], true)
            ? $report->bone_broth_flavour
            : 'either';

        $chicken = self::flavourData('chicken');
        $beef = self::flavourData('beef');

        // Gate 3: the RELEVANT flavour's product must exist with a url (chicken→chicken,
        // beef→beef; for "either", at least one of the two). Otherwise nothing to buy.
        $hasRelevant = match ($flavour) {
            'chicken' => $chicken !== null,
            'beef' => $beef !== null,
            default => $chicken !== null || $beef !== null,
        };
        if (! $hasRelevant) {
            return null;
        }

        $petName = (string) ($report->petField('name') ?: 'your dog');

        return [
            'flavour' => $flavour,
            // The pre-selected tab: chicken/beef preselect that flavour; "either"
            // preselects neither (null) so the switcher invites a choice.
            'selected' => in_array($flavour, ['chicken', 'beef'], true) ? $flavour : null,
            'pet_name' => $petName,
            'recommendation' => self::recommendationLine($flavour, $petName),
            'wording' => self::WORDING,
            'chicken' => $chicken,
            'beef' => $beef,
        ];
    }
}
