<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\PlanVariant;
use App\Support\PlanInstantiation;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlanInstantiation — the single variant-aware seam that turns a plan into a
 * report's steps + subscription snapshot. Locks: swaps apply (id + dose/qty/
 * duration), checkout url + variant key + includes reflect the variant, the
 * combined-gap review reason surfaces, and a flagless pet / variant-less plan is
 * byte-identical to the pre-variant behaviour.
 */
class PlanInstantiationTest extends TestCase
{
    private CatalogProduct $amr;

    private CatalogProduct $amrRf;

    private CatalogProduct $prebiotic;

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

        $this->amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35.00, 'url' => 'https://x/amr']);
        $this->amrRf = CatalogProduct::create(['name' => 'AMR Rosemary-Free', 'price' => 36.50, 'url' => 'https://x/amr-rf']);
        $this->prebiotic = CatalogProduct::create(['name' => 'PetBiome Prebiotic', 'price' => 30.00]);
    }

    private function makePlan(): Plan
    {
        $plan = Plan::create([
            'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://base.test/checkout/BASE',
            'subscription_price' => '£29.75 / month', 'subscription_full_price' => '£35 / month',
            'subscription_billing_note' => 'Billed monthly', 'subscription_saving_label' => '15% off',
            'subscription_includes' => ['PetBiome AMR', 'PetBiome Prebiotic'],
        ]);

        // Step 1: AMR (included, swappable). Step 2: prose. Step 3: Prebiotic (included).
        $s1 = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $s1->products()->create([
            'catalog_product_id' => $this->amr->id, 'duration' => '3 months',
            'quantity' => '3 (one pouch per month)', 'dose' => 'Base dose', 'inclusion' => 'included', 'position' => 0,
        ]);
        $plan->steps()->create(['type' => 'prose', 'step_title' => 'Step 2', 'stage_label' => 'Alongside', 'body' => 'Base body', 'tip' => 'Base tip', 'position' => 1]);
        $s3 = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 3', 'stage_label' => 'Phase 2', 'position' => 2]);
        $s3->products()->create([
            'catalog_product_id' => $this->prebiotic->id, 'duration' => '4 months',
            'quantity' => '4', 'dose' => 'Preb dose', 'inclusion' => 'included', 'position' => 0,
        ]);

        return $plan->fresh();
    }

    private function addSensitiveVariant(Plan $plan, ?string $url = 'https://sensitive.test/checkout/S'): PlanVariant
    {
        $variant = $plan->variants()->create(['condition' => PlanVariant::CONDITION_SENSITIVE, 'subscription_url' => $url, 'enabled' => true]);
        $variant->productOverrides()->create([
            'from_catalog_product_id' => $this->amr->id,
            'to_catalog_product_id' => $this->amrRf->id,
            'dose' => 'Sensitive dose', 'quantity' => '3 (one RF pouch)', 'duration' => '3 months (RF)',
        ]);

        return $variant;
    }

    private function pet(bool $sensitive = false, bool $large = false): Pet
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);

        return Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'is_sensitive' => $sensitive, 'is_large_breed' => $large]);
    }

    // ── Base (flagless / variant-less) — byte-identical content ───────────────
    public function test_flagless_pet_produces_base_products_url_and_includes(): void
    {
        $plan = $this->makePlan();
        $this->addSensitiveVariant($plan);   // variant exists but pet has no flags

        $out = PlanInstantiation::build($plan, $this->pet(), []);

        // Step 1 product is the BASE AMR with base dose/qty/duration.
        $p = $out['steps'][0]['products'][0];
        $this->assertSame($this->amr->id, $p['catalog_product_id']);
        $this->assertSame('Base dose', $p['dose']);
        $this->assertSame('3 (one pouch per month)', $p['quantity']);
        $this->assertSame('3 months', $p['duration']);
        $this->assertSame('included', $p['inclusion']);
        $this->assertSame('[copy to be generated]', $p['how_it_helps']);

        // Prose step untouched, products empty.
        $this->assertSame('prose', $out['steps'][1]['type']);
        $this->assertSame('Base body', $out['steps'][1]['body']);
        $this->assertSame([], $out['steps'][1]['products']);

        // Snapshot: base url, variant null, base includes.
        $this->assertSame('https://base.test/checkout/BASE', $out['subscription_snapshot']['url']);
        $this->assertNull($out['subscription_snapshot']['variant']);
        $this->assertSame(
            [['name' => 'PetBiome AMR', 'price' => '35.00'], ['name' => 'PetBiome Prebiotic', 'price' => '30.00']],
            $out['subscription_snapshot']['includes'],
        );
        $this->assertNull($out['variant']);
        $this->assertNull($out['needs_review_reason']);
    }

    public function test_plan_with_no_variants_is_base_even_for_a_flagged_pet(): void
    {
        $plan = $this->makePlan();   // no variants defined

        $out = PlanInstantiation::build($plan, $this->pet(sensitive: true, large: true), []);

        $this->assertSame($this->amr->id, $out['steps'][0]['products'][0]['catalog_product_id']);
        $this->assertSame('https://base.test/checkout/BASE', $out['subscription_snapshot']['url']);
        $this->assertNull($out['subscription_snapshot']['variant']);
        // Both-flagged with NO variant at all → combined-gap review reason (base fallback).
        $this->assertNotNull($out['needs_review_reason']);
        $this->assertStringContainsString('used the base plan', $out['needs_review_reason']);
    }

    public function test_null_pet_resolves_to_base(): void
    {
        $plan = $this->makePlan();
        $this->addSensitiveVariant($plan);

        $out = PlanInstantiation::build($plan, null, []);

        $this->assertSame($this->amr->id, $out['steps'][0]['products'][0]['catalog_product_id']);
        $this->assertSame('https://base.test/checkout/BASE', $out['subscription_snapshot']['url']);
        $this->assertNull($out['needs_review_reason']);
    }

    // ── Variant applied ──────────────────────────────────────────────────────
    public function test_sensitive_pet_swaps_product_dosage_url_and_includes(): void
    {
        $plan = $this->makePlan();
        $this->addSensitiveVariant($plan);

        $out = PlanInstantiation::build($plan, $this->pet(sensitive: true), []);

        // Step 1 product is SWAPPED to Rosemary-Free with the override dose/qty/duration.
        $p = $out['steps'][0]['products'][0];
        $this->assertSame($this->amrRf->id, $p['catalog_product_id']);
        $this->assertSame('Sensitive dose', $p['dose']);
        $this->assertSame('3 (one RF pouch)', $p['quantity']);
        $this->assertSame('3 months (RF)', $p['duration']);

        // Non-swapped Prebiotic step is untouched.
        $this->assertSame($this->prebiotic->id, $out['steps'][2]['products'][0]['catalog_product_id']);
        $this->assertSame('Preb dose', $out['steps'][2]['products'][0]['dose']);

        // Snapshot: variant url + key, includes show the swapped product (name + price).
        $this->assertSame('https://sensitive.test/checkout/S', $out['subscription_snapshot']['url']);
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $out['subscription_snapshot']['variant']);
        $this->assertSame(
            [['name' => 'AMR Rosemary-Free', 'price' => '36.50'], ['name' => 'PetBiome Prebiotic', 'price' => '30.00']],
            $out['subscription_snapshot']['includes'],
        );
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $out['variant']);
        $this->assertNull($out['needs_review_reason']);   // single flag + matching variant → no gap
    }

    public function test_variant_with_null_url_keeps_base_checkout_but_still_swaps(): void
    {
        $plan = $this->makePlan();
        $this->addSensitiveVariant($plan, url: null);   // swap but no link override

        $out = PlanInstantiation::build($plan, $this->pet(sensitive: true), []);

        $this->assertSame($this->amrRf->id, $out['steps'][0]['products'][0]['catalog_product_id']);
        $this->assertSame('https://base.test/checkout/BASE', $out['subscription_snapshot']['url']);   // inherited
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $out['subscription_snapshot']['variant']);
    }

    public function test_combined_gap_sets_review_reason_when_only_single_variant(): void
    {
        $plan = $this->makePlan();
        $this->addSensitiveVariant($plan);   // only a sensitive variant; pet is BOTH

        $out = PlanInstantiation::build($plan, $this->pet(sensitive: true, large: true), []);

        // Resolves to the sensitive variant (swap applied) but flags the missing combined variant.
        $this->assertSame($this->amrRf->id, $out['steps'][0]['products'][0]['catalog_product_id']);
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $out['variant']);
        $this->assertNotNull($out['needs_review_reason']);
        $this->assertStringContainsString('no combined plan variant is defined', $out['needs_review_reason']);
    }

    // ── Review-flag merge helper ─────────────────────────────────────────────
    public function test_with_variant_review_flag_merges_and_is_idempotent(): void
    {
        // Null reason → unchanged.
        $this->assertNull(ReportGeneration::withVariantReviewFlag(null, null));

        $existing = ['detected_at' => '2026-06-26T00:00:00+00:00', 'issues' => [
            ['code' => 'bad_score_enum', 'severity' => 'warning', 'tier' => 'deterministic', 'detail' => 'x'],
        ]];

        $merged = ReportGeneration::withVariantReviewFlag($existing, 'Combined gap reason.');
        $codes = array_column($merged['issues'], 'code');
        $this->assertContains('bad_score_enum', $codes);            // existing kept
        $this->assertContains(ReportGeneration::VARIANT_GAP_CODE, $codes);
        $this->assertSame('2026-06-26T00:00:00+00:00', $merged['detected_at']);   // not overwritten

        // Re-applying does NOT duplicate the variant-gap row.
        $again = ReportGeneration::withVariantReviewFlag($merged, 'Combined gap reason (re-applied).');
        $variantRows = array_filter($again['issues'], fn ($i) => $i['code'] === ReportGeneration::VARIANT_GAP_CODE);
        $this->assertCount(1, $variantRows);
        $this->assertSame('Combined gap reason (re-applied).', array_values($variantRows)[0]['detail']);
    }
}
