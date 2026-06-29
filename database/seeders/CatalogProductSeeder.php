<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use Illuminate\Database\Seeder;

class CatalogProductSeeder extends Seeder
{
    /**
     * The live shop products the Plans feature references. Plans reference
     * these by id (the catalogue is the single source of truth for name/price/
     * image), so they must exist before PlanSeeder runs.
     *
     * Matched/upserted by `name` so this is idempotent and does not disturb any
     * other catalogue rows (e.g. the existing trigger-tagged placeholders).
     *
     * url / price / image_path are the CONFIRMED live-store values. Note: the
     * PetBiome Prebiotic url (/products/petbiome-amr) is correct per the client —
     * it is NOT a typo, despite looking like the AMR handle.
     */
    public const PRODUCTS = [
        [
            'name' => 'PetBiome AMR',
            'price' => 35.00,
            'url' => 'https://biome4pets.com/products/petbiome-amr-1',
            'image_path' => 'https://biome4pets.com/cdn/shop/files/Copy_of_www.Biome4pets.com_amr_1024_x_1024_px_1.png?v=1757444685',
        ],
        [
            'name' => 'PetBiome Prebiotic',
            'price' => 35.00,
            'url' => 'https://biome4pets.com/products/petbiome-amr', // confirmed correct (not a typo)
            'image_path' => 'https://biome4pets.com/cdn/shop/files/Copy_of_www.Biome4pets.com_amr_1024_x_1024_px_3.png?v=1757445187',
        ],
        [
            'name' => 'Antimicrobic',
            'price' => 35.00,
            'url' => 'https://biome4pets.com/products/antimicrobic',
            'image_path' => 'https://biome4pets.com/cdn/shop/files/www.Biome4pets.com_Antimic_1024_x_1024_px.png?v=1757449553',
        ],
        [
            'name' => 'PetBiome Maintenance',
            'price' => 35.00,
            'url' => 'https://biome4pets.com/products/petbiome-maintenance-1',
            'image_path' => 'https://biome4pets.com/cdn/shop/files/www.Biome4pets.com_mainten_1024_x_1024_px_1.png?v=1757537137',
        ],
        [
            'name' => 'Gut Renew',
            'price' => 130.00,
            'url' => 'https://biome4pets.com/products/gut-renew',
            'image_path' => 'https://biome4pets.com/cdn/shop/files/gut_renew_1024_x_1024_pxa_2f8d2109-6483-4921-8e93-838ae6efac33.png?v=1758089328',
        ],
        [
            'name' => 'PetBiome Gut Microbiome Test Kit',
            'price' => 180.00,
            // The retest kit is offered as an optional add-on at a 30% subscription
            // discount (£180 → £126), the figure formerly hardcoded into the report.
            'subscription_discount_percent' => 30,
            'url' => 'https://biome4pets.com/products/petbiome-microbiome-test-kit',
            'image_path' => 'https://biome4pets.com/cdn/shop/files/Advanced_Gut_Microbiome_Test.png?v=1779191848',
        ],
        [
            // Rosemary-free AMR, used only by the three sensitive plan VARIANTS
            // (swapped in for the standard 'PetBiome AMR' via the builder UI). It
            // carries NO trigger below, so it never auto-matches into a report.
            'name' => 'PetBiome AMR (Rosemary Free)',
            'price' => 35.00,
            'url' => 'https://biome4pets.com/products/petbiome-amr-rosemary-free',
            'image_path' => 'https://biome4pets.com/cdn/shop/files/New_shopify_amr_RF.png',
            'description' => 'It is a combination of the anti-microbial bioactive polyphenols found in oregano, '
                .'thyme, berberine, and a soil-based probiotic. Bacillus subtilis, Bacillus coagulins, Bacillus '
                .'clausii. Lactobacillus, Bifidobacteria. Its use and reasons for supplementing can be found on '
                .'the veterinary summary page of your report. Feed for two months, it is also possible to feed '
                .'AMR continuously.',
        ],
    ];

    /**
     * Report auto-match triggers attached to each real product, keyed by product
     * name and resolved by name below (never by hardcoded id), so a fresh seed
     * maps the catalog_product_trigger table onto the live products:
     *   AMR          → PetBiome AMR
     *   Prebiotic    → PetBiome Prebiotic
     *   Antimicrobic → Antimicrobic
     *   FMT          → Gut Renew   (Gut Renew is the FMT product, Plan D step 1)
     * 'Biotic Boost' is intentionally retired — not seeded. Products absent from
     * this map (Maintenance, Test Kit) carry no trigger.
     */
    public const TRIGGERS = [
        'PetBiome AMR' => ['AMR'],
        'PetBiome Prebiotic' => ['Prebiotic'],
        'Antimicrobic' => ['Antimicrobic'],
        'Gut Renew' => ['FMT'],
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $product) {
            $model = CatalogProduct::updateOrCreate(
                ['name' => $product['name']],
                [
                    'price' => $product['price'],
                    // Null for every product except the retest kit; products without
                    // a configured discount show no discount line on the report.
                    'subscription_discount_percent' => $product['subscription_discount_percent'] ?? null,
                    'url' => $product['url'],
                    'image_path' => $product['image_path'],
                    // Only the AMR (Rosemary Free) product carries a description; the
                    // others are null (unchanged from before).
                    'description' => $product['description'] ?? null,
                    'is_active' => true,
                ],
            );

            // Attach this product's triggers. The trigger_codes mutator clears
            // existing entries first, so this stays idempotent; products with no
            // mapping get an empty set (no catalog_product_trigger rows).
            $model->trigger_codes = self::TRIGGERS[$product['name']] ?? [];
        }
    }
}
