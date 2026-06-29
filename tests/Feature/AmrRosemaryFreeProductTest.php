<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use Database\Seeders\CatalogProductSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "PetBiome AMR (Rosemary Free)" catalogue product is seeded by an additive,
 * idempotent data migration (the no-SSH cPanel deploy runs migrations via the web
 * runner). It backs the three sensitive plan variants, which are linked manually
 * in the builder — so this product must exist, carry no auto-match trigger, and
 * never disturb the standard "PetBiome AMR" it is swapped from.
 */
class AmrRosemaryFreeProductTest extends TestCase
{
    private const NAME = 'PetBiome AMR (Rosemary Free)';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    /** The migration ALONE (no seeder) creates the product with the confirmed
     *  live-store details — this is exactly what the web migration runner does. */
    public function test_migration_seeds_the_product_with_correct_details(): void
    {
        $product = CatalogProduct::where('name', self::NAME)->first();

        $this->assertNotNull($product, 'AMR Rosemary-Free product should be seeded by the migration');
        $this->assertSame('35.00', (string) $product->price);
        $this->assertNull($product->subscription_discount_percent);
        $this->assertSame('https://biome4pets.com/products/petbiome-amr-rosemary-free', $product->url);
        $this->assertSame('https://biome4pets.com/cdn/shop/files/New_shopify_amr_RF.png', $product->image_path);
        $this->assertStringContainsString('oregano', $product->description);
        $this->assertTrue($product->is_active);
    }

    /** It must NOT carry a trigger — it only reaches a report via a manual variant
     *  swap, never by auto-matching like the standard AMR (which has the 'AMR' trigger). */
    public function test_product_has_no_auto_match_trigger(): void
    {
        $product = CatalogProduct::where('name', self::NAME)->first();

        $this->assertSame([], $product->trigger_codes);
    }

    /** With the full catalogue seeded, the standard "PetBiome AMR" (the swap source)
     *  is present and untouched, alongside the new product. */
    public function test_standard_amr_is_untouched_alongside_new_product(): void
    {
        (new CatalogProductSeeder)->run();

        $standard = CatalogProduct::where('name', 'PetBiome AMR')->first();
        $this->assertNotNull($standard);
        $this->assertSame('https://biome4pets.com/products/petbiome-amr-1', $standard->url);
        $this->assertSame(['AMR'], $standard->trigger_codes);

        // The new product still exists exactly once next to it.
        $this->assertSame(1, CatalogProduct::where('name', self::NAME)->count());
    }

    /** Migration + seeder both key on name, so re-running never duplicates. The
     *  migration already created the row in setUp; seeding twice keeps it at one. */
    public function test_seeding_is_idempotent(): void
    {
        (new CatalogProductSeeder)->run();
        $countAfterFirstSeed = CatalogProduct::count();

        (new CatalogProductSeeder)->run();

        $this->assertSame(1, CatalogProduct::where('name', self::NAME)->count());
        $this->assertSame($countAfterFirstSeed, CatalogProduct::count());
    }
}
