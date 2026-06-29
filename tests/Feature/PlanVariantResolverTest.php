<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\PlanVariant;
use App\Support\PlanVariantResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlanVariantResolver — Stage 2 of the conditional-variant system. Pure resolution
 * from a pet's flags to (at most) one plan variant, most-specific-first, never
 * merging two variants, with a deterministic combined-gap review reason. Covers the
 * full matrix; the resolver is not wired into generation/checkout/UI yet.
 */
class PlanVariantResolverTest extends TestCase
{
    private CatalogProduct $amr;

    private CatalogProduct $amrRosemaryFree;

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

        $this->amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35.00]);
        $this->amrRosemaryFree = CatalogProduct::create(['name' => 'AMR Rosemary-Free', 'price' => 35.00]);
    }

    private function makePlan(string $key = 'restore-rebalance', ?string $url = 'https://base.test/checkout/BASE'): Plan
    {
        return Plan::create([
            'key' => $key, 'name' => ucfirst($key), 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => $url,
        ]);
    }

    private function makePet(bool $sensitive = false, bool $large = false): Pet
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);

        return Pet::create([
            'client_id' => $client->id, 'name' => 'Biscuit',
            'is_sensitive' => $sensitive, 'is_large_breed' => $large,
        ]);
    }

    /** Add an enabled (or disabled) variant with an AMR → Rosemary-Free swap. */
    private function addVariant(Plan $plan, string $condition, ?string $url, bool $enabled = true): PlanVariant
    {
        $variant = $plan->variants()->create([
            'condition' => $condition, 'subscription_url' => $url, 'enabled' => $enabled,
        ]);
        $variant->productOverrides()->create([
            'from_catalog_product_id' => $this->amr->id,
            'to_catalog_product_id' => $this->amrRosemaryFree->id,
            'dose' => 'One pouch daily', 'quantity' => '3 (one pouch per month)', 'duration' => '3 months',
        ]);

        return $variant;
    }

    // ── Base (no resolution) ─────────────────────────────────────────────────
    public function test_no_flags_resolves_to_base(): void
    {
        $plan = $this->makePlan();
        $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, 'https://sensitive.test/checkout/S');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: false, large: false));

        $this->assertNull($r['variant']);
        $this->assertNull($r['condition_key']);
        $this->assertSame('https://base.test/checkout/BASE', $r['checkout_url']);   // base url
        $this->assertSame([], $r['product_swaps']);
        $this->assertNull($r['needs_review_reason']);
    }

    public function test_plan_with_no_variants_always_resolves_to_base(): void
    {
        // A Maintain/Protect-style plan (no AMR, no variants) — base for every flag combo.
        $plan = $this->makePlan('maintain-protect');

        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$s, $l]) {
            $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: $s, large: $l));
            $this->assertNull($r['variant'], "flags s=$s l=$l should be base");
            $this->assertSame('https://base.test/checkout/BASE', $r['checkout_url']);
            $this->assertSame([], $r['product_swaps']);
        }
    }

    // ── Single-axis matches ──────────────────────────────────────────────────
    public function test_sensitive_only_with_sensitive_variant_resolves_to_it(): void
    {
        $plan = $this->makePlan();
        $variant = $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, 'https://sensitive.test/checkout/S');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true));

        $this->assertTrue($variant->is($r['variant']));
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $r['condition_key']);
        $this->assertSame('https://sensitive.test/checkout/S', $r['checkout_url']);   // variant url override
        $this->assertNull($r['needs_review_reason']);

        // Swap map shape: from-id => {to-id, dose, quantity, duration}.
        $this->assertArrayHasKey($this->amr->id, $r['product_swaps']);
        $this->assertSame([
            'to_catalog_product_id' => $this->amrRosemaryFree->id,
            'dose' => 'One pouch daily',
            'quantity' => '3 (one pouch per month)',
            'duration' => '3 months',
        ], $r['product_swaps'][$this->amr->id]);
    }

    public function test_large_only_with_large_variant_resolves_to_it(): void
    {
        $plan = $this->makePlan();
        $variant = $this->addVariant($plan, PlanVariant::CONDITION_LARGE, 'https://large.test/checkout/L');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(large: true));

        $this->assertTrue($variant->is($r['variant']));
        $this->assertSame(PlanVariant::CONDITION_LARGE, $r['condition_key']);
        $this->assertSame('https://large.test/checkout/L', $r['checkout_url']);
        $this->assertNull($r['needs_review_reason']);
    }

    public function test_variant_with_null_url_inherits_base_checkout_url(): void
    {
        $plan = $this->makePlan();
        $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, url: null);   // no link override

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true));

        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $r['condition_key']);
        $this->assertSame('https://base.test/checkout/BASE', $r['checkout_url']);   // inherited
    }

    // ── Combined (both flags) ────────────────────────────────────────────────
    public function test_both_flags_with_combined_variant_resolves_to_combined_no_review(): void
    {
        $plan = $this->makePlan();
        // Even with single-axis variants present, the dedicated combined variant wins.
        $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, 'https://sensitive.test/checkout/S');
        $this->addVariant($plan, PlanVariant::CONDITION_LARGE, 'https://large.test/checkout/L');
        $combined = $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE_LARGE, 'https://combined.test/checkout/SL');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true, large: true));

        $this->assertTrue($combined->is($r['variant']));
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE_LARGE, $r['condition_key']);
        $this->assertSame('https://combined.test/checkout/SL', $r['checkout_url']);
        $this->assertNull($r['needs_review_reason']);   // exact match → no gap flag
    }

    public function test_both_flags_with_only_sensitive_variant_falls_back_and_flags_review(): void
    {
        $plan = $this->makePlan();
        $variant = $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, 'https://sensitive.test/checkout/S');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true, large: true));

        $this->assertTrue($variant->is($r['variant']));
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $r['condition_key']);
        $this->assertNotNull($r['needs_review_reason']);
        $this->assertStringContainsString('no combined plan variant is defined', $r['needs_review_reason']);
        $this->assertStringContainsString('used the sensitive variant', $r['needs_review_reason']);
    }

    public function test_both_flags_with_only_large_variant_falls_back_and_flags_review(): void
    {
        $plan = $this->makePlan();
        $variant = $this->addVariant($plan, PlanVariant::CONDITION_LARGE, 'https://large.test/checkout/L');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true, large: true));

        $this->assertTrue($variant->is($r['variant']));
        $this->assertSame(PlanVariant::CONDITION_LARGE, $r['condition_key']);
        $this->assertStringContainsString('used the large variant', $r['needs_review_reason']);
    }

    public function test_both_flags_with_no_variants_resolves_to_base_and_flags_review(): void
    {
        $plan = $this->makePlan();   // no variants at all

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true, large: true));

        $this->assertNull($r['variant']);
        $this->assertNull($r['condition_key']);
        $this->assertSame('https://base.test/checkout/BASE', $r['checkout_url']);
        $this->assertSame([], $r['product_swaps']);
        $this->assertNotNull($r['needs_review_reason']);
        $this->assertStringContainsString('used the base plan', $r['needs_review_reason']);
    }

    // ── Precedence & enabled-only ────────────────────────────────────────────
    public function test_precedence_sensitive_chosen_over_large_for_both_flagged_pet(): void
    {
        $plan = $this->makePlan();
        // No combined variant; both single variants present → sensitive must win.
        $sensitive = $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, 'https://sensitive.test/checkout/S');
        $this->addVariant($plan, PlanVariant::CONDITION_LARGE, 'https://large.test/checkout/L');

        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true, large: true));

        $this->assertTrue($sensitive->is($r['variant']));
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $r['condition_key']);
        $this->assertSame('https://sensitive.test/checkout/S', $r['checkout_url']);
        // Still a gap (no combined variant) → review reason set.
        $this->assertStringContainsString('used the sensitive variant', $r['needs_review_reason']);
    }

    public function test_disabled_variant_is_skipped_as_if_undefined(): void
    {
        $plan = $this->makePlan();
        // Sensitive variant is DISABLED → must be ignored.
        $this->addVariant($plan, PlanVariant::CONDITION_SENSITIVE, 'https://sensitive.test/checkout/S', enabled: false);

        // Sensitive-only pet: disabled sensitive variant ignored → base.
        $r = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true));
        $this->assertNull($r['variant']);
        $this->assertSame('https://base.test/checkout/BASE', $r['checkout_url']);

        // Both-flagged pet with an enabled LARGE variant + disabled sensitive →
        // skips disabled sensitive, lands on large, flags the combined gap.
        $large = $this->addVariant($plan, PlanVariant::CONDITION_LARGE, 'https://large.test/checkout/L');
        $r2 = PlanVariantResolver::resolve($plan, $this->makePet(sensitive: true, large: true));
        $this->assertTrue($large->is($r2['variant']));
        $this->assertSame(PlanVariant::CONDITION_LARGE, $r2['condition_key']);
        $this->assertStringContainsString('used the large variant', $r2['needs_review_reason']);
    }
}
