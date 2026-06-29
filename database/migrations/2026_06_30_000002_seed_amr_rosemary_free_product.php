<?php

use App\Models\CatalogProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the catalogue product "PetBiome AMR (Rosemary Free)" — the rosemary-free
 * AMR powder used by the three sensitive Plan VARIANTS (added manually via the
 * builder UI afterwards; this migration adds ONLY the product, no variants/links).
 *
 * PURELY ADDITIVE + IDEMPOTENT, and runs through the web migration runner (our
 * no-SSH cPanel deploy), exactly like the breeds / tub-pouch data migrations:
 *   - updateOrCreate keyed on `name`, so re-running never duplicates and a
 *     partial row is healed to the canonical details.
 *   - No trigger is attached: unlike the standard "PetBiome AMR" (which carries
 *     the 'AMR' auto-match trigger), this product must NOT auto-match into reports.
 *     It only ever reaches a report through a manual plan-variant swap.
 *   - Every other catalogue row is left untouched.
 *
 * The standard product it is swapped FROM in the builder is "PetBiome AMR"
 * (catalog_products.id = 1 on live). The CatalogProductSeeder carries the same
 * row so a fresh `migrate --seed` install is identical to a migrated live db.
 */
return new class extends Migration
{
    private const NAME = 'PetBiome AMR (Rosemary Free)';

    public function up(): void
    {
        CatalogProduct::updateOrCreate(
            ['name' => self::NAME],
            [
                'price' => 35.00,
                'subscription_discount_percent' => null,
                'url' => 'https://biome4pets.com/products/petbiome-amr-rosemary-free',
                'image_path' => 'https://biome4pets.com/cdn/shop/files/New_shopify_amr_RF.png',
                'description' => 'It is a combination of the anti-microbial bioactive polyphenols found in oregano, '
                    .'thyme, berberine, and a soil-based probiotic. Bacillus subtilis, Bacillus coagulins, Bacillus '
                    .'clausii. Lactobacillus, Bifidobacteria. Its use and reasons for supplementing can be found on '
                    .'the veterinary summary page of your report. Feed for two months, it is also possible to feed '
                    .'AMR continuously.',
                'is_active' => true,
            ],
        );
    }

    /**
     * Remove the product again — but only when nothing references it, so a rollback
     * can never orphan a manually-created plan variant or an already-generated
     * report. If it is in use the row is kept (the migration still rolls back).
     */
    public function down(): void
    {
        $product = CatalogProduct::where('name', self::NAME)->first();
        if (! $product) {
            return;
        }

        $referenced = DB::table('plan_step_products')->where('catalog_product_id', $product->id)->exists()
            || DB::table('report_step_products')->where('catalog_product_id', $product->id)->exists()
            || DB::table('plan_variant_product_overrides')
                ->where('from_catalog_product_id', $product->id)
                ->orWhere('to_catalog_product_id', $product->id)
                ->exists();

        if (! $referenced) {
            $product->delete();
        }
    }
};
