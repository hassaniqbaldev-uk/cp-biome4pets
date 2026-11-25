<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Deploy-safe seeder for the THREE retest-only plans (retest-amr-only,
 * retest-prebiotic-only, retest-spirochaetes) and their steps/products.
 *
 * Idempotent + NON-DESTRUCTIVE by design: each plan is inserted only when ABSENT
 * (Plan::firstOrCreate by key; steps/products seeded only when the plan row was just
 * created). Running it twice never duplicates, and it NEVER updates or touches an
 * existing plan row, its steps, its trigger conditions, or any other row — so it can be
 * run against a live DB whose four base plans may have been edited in the admin without
 * disturbing them. It is the single source of truth for the three retest plans;
 * PlanSeeder owns only the four base plans. DatabaseSeeder runs this AFTER PlanSeeder.
 *
 * The three plans carry NO plan_trigger_conditions and is_fallback = false, so they are
 * invisible to first-test matching and only ever selected by the retest override by key.
 */
class RetestPlansSeeder extends Seeder
{
    private const DEFAULT_DOSE = 'Follow recommended dose on label.';

    /** Plans are listed after the four base plans (positions 0–3). */
    private const BASE_POSITION_OFFSET = 4;

    public function run(): void
    {
        // Resolve product ids by NAME (never hardcoded ids). firstOrCreate only CREATES a
        // product when genuinely absent — on live the six shop products already exist, so
        // nothing is changed; a stub is logged if one is ever missing.
        $stubbed = [];
        $idFor = function (string $name) use (&$stubbed): int {
            $product = CatalogProduct::firstOrCreate(['name' => $name], ['is_active' => true]);
            if ($product->wasRecentlyCreated) {
                $stubbed[] = $name;
                Log::warning("RetestPlansSeeder stubbed a missing catalogue product: {$name}");
            }

            return $product->id;
        };

        $created = 0;
        $skipped = 0;

        foreach ($this->planDefinitions($idFor) as $index => $def) {
            // Insert-if-absent by key. An existing plan (edited or not) is left entirely
            // alone — no attribute update, no step rebuild, no trigger-condition touch.
            $plan = Plan::firstOrCreate(
                ['key' => $def['key']],
                [
                    'name' => $def['name'],
                    'trigger_description' => $def['trigger_description'],
                    'enabled' => true,
                    // Never a fallback and no trigger conditions → never first-test matched.
                    'is_fallback' => false,
                    'match_priority' => 1000,
                    'species_availability' => 'both',
                    'position' => self::BASE_POSITION_OFFSET + $index,
                    'subscription_available' => $def['subscription']['available'],
                    'subscription_price' => $def['subscription']['price'],
                    'subscription_full_price' => $def['subscription']['full_price'] ?? null,
                    'subscription_billing_note' => $def['subscription']['billing_note'],
                    'subscription_includes' => $def['subscription']['includes'],
                    'subscription_saving_label' => $def['subscription']['saving_label'] ?? null,
                ],
            );

            if (! $plan->wasRecentlyCreated) {
                $skipped++;

                continue; // already present — touch nothing.
            }

            foreach ($def['steps'] as $stepIndex => $stepDef) {
                $step = $plan->steps()->create([
                    'type' => $stepDef['type'],
                    'step_title' => $stepDef['step_title'],
                    'stage_label' => $stepDef['stage_label'] ?? null,
                    'body' => $stepDef['body'] ?? null,
                    'tip' => $stepDef['tip'] ?? null,
                    'position' => $stepIndex,
                ]);

                foreach ($stepDef['products'] ?? [] as $productIndex => $productDef) {
                    $step->products()->create([
                        'catalog_product_id' => $productDef['catalog_product_id'],
                        'duration' => $productDef['duration'] ?? null,
                        'quantity' => $productDef['quantity'] ?? null,
                        'dose' => $productDef['dose'] ?? self::DEFAULT_DOSE,
                        'inclusion' => $productDef['inclusion'] ?? 'included',
                        'position' => $productIndex,
                    ]);
                }
            }

            $created++;
        }

        if (! empty($stubbed)) {
            $this->command?->warn('RetestPlansSeeder stubbed missing catalogue products: '.implode(', ', array_unique($stubbed)));
        }
        $this->command?->info("RetestPlansSeeder: created {$created} retest plan(s), left {$skipped} existing untouched.");
    }

    /**
     * The three retest-only plans, each built from existing products. Placeholder names
     * (the client will rename). Subscription left unavailable — prices are hidden on
     * retests. Resolved to catalogue ids by name via $idFor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function planDefinitions(callable $idFor): array
    {
        $maintenanceStep = fn (string $title, string $stage): array => [
            'type' => 'product',
            'step_title' => $title,
            'stage_label' => $stage,
            'products' => [[
                'catalog_product_id' => $idFor('PetBiome Maintenance'),
                'duration' => 'Ongoing',
                'quantity' => '1 per month (subscription)',
                'inclusion' => 'included',
            ]],
        ];

        $courseStep = fn (string $product, string $title, string $stage, int $months): array => [
            'type' => 'product',
            'step_title' => $title,
            'stage_label' => $stage,
            'products' => [[
                'catalog_product_id' => $idFor($product),
                'duration' => $months.' month'.($months === 1 ? '' : 's'),
                'quantity' => $months.' (one pouch per month)',
                'inclusion' => 'included',
            ]],
        ];

        return [
            // AMR Reset (retest): AMR 3 months → Maintenance ongoing.
            [
                'key' => 'retest-amr-only',
                'name' => 'AMR Reset (retest)',
                'trigger_description' => 'Retest rule: AMR reset',
                'subscription' => [
                    'available' => false,
                    'price' => null,
                    'billing_note' => null,
                    'includes' => ['PetBiome AMR', 'PetBiome Maintenance'],
                ],
                'steps' => [
                    $courseStep('PetBiome AMR', 'Step 1: Microbiome Reset', 'Phase 1 · Months 1–3', 3),
                    $maintenanceStep('Step 2: Maintain Gut Microbiome Health', 'Phase 2 · Ongoing'),
                ],
            ],

            // Prebiotic Reset (retest): Prebiotic 6 months → Maintenance ongoing.
            [
                'key' => 'retest-prebiotic-only',
                'name' => 'Prebiotic Reset (retest)',
                'trigger_description' => 'Retest rule: prebiotic reset',
                'subscription' => [
                    'available' => false,
                    'price' => null,
                    'billing_note' => null,
                    'includes' => ['PetBiome Prebiotic', 'PetBiome Maintenance'],
                ],
                'steps' => [
                    $courseStep('PetBiome Prebiotic', 'Step 1: Rebuild & Restore', 'Phase 1 · Months 1–6', 6),
                    $maintenanceStep('Step 2: Maintain Gut Microbiome Health', 'Phase 2 · Ongoing'),
                ],
            ],

            // Spirochaetes Reset (retest): Antimicrobic 2 months → Prebiotic 4 months → Maintenance ongoing.
            [
                'key' => 'retest-spirochaetes',
                'name' => 'Spirochaetes Reset (retest)',
                'trigger_description' => 'Retest rule: spirochaetes reset',
                'subscription' => [
                    'available' => false,
                    'price' => null,
                    'billing_note' => null,
                    'includes' => ['Antimicrobic', 'PetBiome Prebiotic', 'PetBiome Maintenance'],
                ],
                'steps' => [
                    $courseStep('Antimicrobic', 'Step 1: Targeted Support', 'Phase 1 · Months 1–2', 2),
                    $courseStep('PetBiome Prebiotic', 'Step 2: Rebuild & Restore', 'Phase 2 · Months 3–6', 4),
                    $maintenanceStep('Step 3: Maintain Gut Microbiome Health', 'Phase 3 · Ongoing'),
                ],
            ],
        ];
    }
}
