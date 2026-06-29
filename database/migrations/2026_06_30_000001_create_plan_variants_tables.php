<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditional plan-variant system — Stage 1 data model.
 *
 * Two ADDITIVE tables only; no existing column is touched, so every current plan
 * (which has zero variants) keeps behaving exactly as it does today. A variant is
 * an OVERRIDE layer on a base plan, keyed by a pet-condition string, storing only
 * the deltas: an optional checkout-link override, optional bundle-price overrides,
 * and per-product swaps (with optional dose/quantity/duration overrides).
 *
 * INERT until later stages: nothing reads these tables yet (no generation /
 * checkout / UI wiring), and there are no rows until an admin defines a variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            // Controlled vocabulary (PlanVariant::CONDITION_*): 'sensitive',
            // 'large', 'sensitive_large'. One variant per condition per plan.
            $table->string('condition');
            // Checkout-link override. Null = inherit the base plan's subscription_url.
            $table->string('subscription_url', 500)->nullable();
            // Optional bundle-price display overrides. Null on any = inherit the base
            // plan's corresponding field (the product swap already carries the
            // swapped product's own name/price, so these are only for a differing
            // bundle headline price).
            $table->string('subscription_price')->nullable();
            $table->string('subscription_full_price')->nullable();
            $table->string('subscription_billing_note')->nullable();
            $table->string('subscription_saving_label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'condition']);
        });

        Schema::create('plan_variant_product_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_variant_id')->constrained()->cascadeOnDelete();
            // The base catalogue product to replace (the match key) and its
            // replacement — both reference live catalogue rows by id.
            $table->foreignId('from_catalog_product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('to_catalog_product_id')->constrained('catalog_products')->cascadeOnDelete();
            // Optional per-swap overrides (future: large-breed dosage). Null =
            // inherit the base plan_step_product's value.
            $table->string('dose')->nullable();
            $table->string('quantity')->nullable();
            $table->string('duration')->nullable();
            $table->timestamps();

            // Explicit short name: the auto-generated one (table + both columns +
            // "_unique") exceeds MySQL's 64-char identifier limit.
            $table->unique(['plan_variant_id', 'from_catalog_product_id'], 'pvpo_variant_from_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_variant_product_overrides');
        Schema::dropIfExists('plan_variants');
    }
};
