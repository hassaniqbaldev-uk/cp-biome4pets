<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PlanSeeder extends Seeder
{
    /**
     * Default dose text used wherever a product doesn't override it.
     */
    private const DEFAULT_DOSE = 'Follow recommended dose on label.';

    /**
     * Default subscribe link. Backfilled onto plans whose subscription_url is
     * still blank — never overwrites a URL an admin has set in the Plans panel.
     */
    private const DEFAULT_SUBSCRIPTION_URL = 'https://biome4pets.com';

    /**
     * The trigger-set → plan mapping, seeded into plan_trigger_conditions +
     * plans.is_fallback / match_priority. Reproduces EXACTLY the (former
     * hardcoded) recommendPlanId() precedence so the data-driven matcher behaves
     * identically out of the box. priority: lower = checked first. conditions:
     * each inner array is an AND-set; the outer array OR-s them.
     */
    private const TRIGGER_CONFIG = [
        'rebuild-renew' => ['priority' => 1, 'fallback' => false, 'conditions' => [['FMT']]],
        'reset-recover' => ['priority' => 2, 'fallback' => false, 'conditions' => [['AMR', 'Antimicrobic']]],
        'restore-rebalance' => ['priority' => 3, 'fallback' => false, 'conditions' => [['AMR', 'Prebiotic']]],
        // The fallback: chosen only when NO triggers fire. No condition rows.
        'maintain-protect' => ['priority' => 1000, 'fallback' => true, 'conditions' => []],
    ];

    /**
     * The four plans, matching their reference HTML files exactly. Factual
     * product fields (name/price/url/image) come from the catalogue. Prose
     * steps carry body/tip as editable DEFAULTS (the report generator rewrites
     * them per pet). Product how_it_helps is NOT stored on plan templates —
     * plan_step_products has no such column (no schema change) and that copy is
     * generated per report.
     *
     * Depends on CatalogProductSeeder having run first.
     */
    public function run(): void
    {
        // Resolve product ids by name once. Any missing product is stubbed so a
        // plan can still be seeded (and the gap is reported in the summary).
        $stubbed = [];
        $idFor = function (string $name) use (&$stubbed): int {
            $product = CatalogProduct::firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );

            if ($product->wasRecentlyCreated) {
                $stubbed[] = $name;
                Log::warning("PlanSeeder stubbed a missing catalogue product: {$name}");
            }

            return $product->id;
        };

        $plans = $this->planDefinitions($idFor);

        // Upsert by key so the four EXISTING plans keep their ids — reports that
        // reference a plan (reports.plan_id) stay linked across reseeds. (A prior
        // delete-and-recreate approach nulled those FKs via nullOnDelete.)
        // subscription_url is intentionally NOT in the payload so an admin's
        // Shopify link set via the Plans resource survives a reseed.
        foreach ($plans as $position => $def) {
            $cfg = self::TRIGGER_CONFIG[$def['key']] ?? ['priority' => 1000, 'fallback' => false, 'conditions' => []];

            $plan = Plan::updateOrCreate(
                ['key' => $def['key']],
                [
                    'name' => $def['name'],
                    'trigger_description' => $def['trigger_description'],
                    'enabled' => true,
                    'is_fallback' => $cfg['fallback'],
                    'match_priority' => $cfg['priority'],
                    'species_availability' => 'both',
                    'position' => $position,
                    'subscription_available' => $def['subscription']['available'],
                    'subscription_price' => $def['subscription']['price'],
                    'subscription_full_price' => $def['subscription']['full_price'] ?? null,
                    'subscription_billing_note' => $def['subscription']['billing_note'],
                    'subscription_includes' => $def['subscription']['includes'],
                    // "15% off" badge on the £29.75 plans (15% off the £35 full price);
                    // null elsewhere (e.g. rebuild-renew's £132 intro).
                    'subscription_saving_label' => $def['subscription']['saving_label'] ?? null,
                ],
            );

            // Seed the real per-plan Loop checkout URL, but never clobber a URL an
            // admin set in the Plans panel. We (re)write only when the field is
            // blank OR still holds the old generic biome4pets.com placeholder — so
            // a fresh seed gets the real Loop URLs and existing placeholders are
            // upgraded, while genuinely custom admin URLs survive.
            $seedUrl = $def['subscription']['url'] ?? self::DEFAULT_SUBSCRIPTION_URL;
            if (blank($plan->subscription_url) || $plan->subscription_url === self::DEFAULT_SUBSCRIPTION_URL) {
                $plan->subscription_url = $seedUrl;
                $plan->save();
            }

            // Rebuild this plan's steps/products in place (DB cascade clears the
            // old plan_step_products); the plan row id is preserved above.
            $plan->steps()->delete();

            foreach ($def['steps'] as $stepIndex => $stepDef) {
                $step = $plan->steps()->create([
                    'type' => $stepDef['type'],
                    'step_title' => $stepDef['step_title'],
                    'stage_label' => $stepDef['stage_label'] ?? null,
                    // Prose defaults (null for product steps).
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

            // Rebuild this plan's trigger conditions in place from TRIGGER_CONFIG,
            // reproducing the former hardcoded recommendPlanId() mapping exactly.
            $plan->triggerConditions()->delete();
            foreach ($cfg['conditions'] as $conditionIndex => $requiredTriggers) {
                $plan->triggerConditions()->create([
                    'position' => $conditionIndex,
                    'required_triggers' => $requiredTriggers,
                ]);
            }
        }

        if (! empty($stubbed)) {
            $this->command?->warn('PlanSeeder stubbed missing catalogue products: ' . implode(', ', array_unique($stubbed)));
        }

        $this->command?->info('Seeded ' . count($plans) . ' plans.');
    }

    private function planDefinitions(callable $idFor): array
    {
        $retestStep = function (string $title, string $stage) use ($idFor): array {
            return [
                'type' => 'product',
                'step_title' => $title,
                'stage_label' => $stage,
                'products' => [[
                    'catalog_product_id' => $idFor('PetBiome Gut Microbiome Test Kit'),
                    'duration' => 'One-off retest',
                    'quantity' => '1',
                    'dose' => 'Single sample collection at home.',
                    'inclusion' => 'optional',
                ]],
            ];
        };

        $maintenanceStep = function (string $title, string $stage) use ($idFor): array {
            return [
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
        };

        return [
            // Plan A — Restore & Rebalance (AMR + Prebiotic)
            [
                'key' => 'restore-rebalance',
                'name' => 'Restore & Rebalance',
                'trigger_description' => 'AMR + Prebiotic recommended',
                'subscription' => [
                    'available' => true,
                    'price' => '£29.75 / month',
                    'full_price' => '£35 / month',
                    'saving_label' => '15% off',
                    'billing_note' => 'Save 15% vs buying separately · billed monthly',
                    'url' => 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/01KVFN3419VW0QBD38EWPY1BWH',
                    'includes' => ['PetBiome AMR', 'PetBiome Prebiotic', 'PetBiome Maintenance'],
                ],
                'steps' => [
                    [
                        'type' => 'product',
                        'step_title' => 'Step 1: Microbiome Reset',
                        'stage_label' => 'Phase 1 · Months 1–3',
                        'products' => [[
                            'catalog_product_id' => $idFor('PetBiome AMR'),
                            'duration' => '3 months (12 weeks)',
                            'quantity' => '3 (one pouch per month)',
                            'inclusion' => 'included',
                        ]],
                    ],
                    [
                        'type' => 'prose',
                        'step_title' => 'Step 2: Implement Dietary Changes',
                        'stage_label' => 'Alongside Phase 1',
                        'body' => 'To create a gut environment less favourable to Clostridium, reduce or eliminate highly processed foods. If suitable for Zenia, add small amounts of raw or lightly cooked meat. Allow at least four weeks for dietary changes to show in the microbiome, and avoid further changes during this window.',
                        'tip' => 'A recent study found daily bone broth reduced Clostridium species in 95% of dogs over four weeks. Home-cooked bone broth offers the same benefit as shop-bought.',
                    ],
                    [
                        'type' => 'product',
                        'step_title' => 'Step 3: Rebuild & Restore',
                        'stage_label' => 'Phase 2 · Months 4–7',
                        'products' => [[
                            'catalog_product_id' => $idFor('PetBiome Prebiotic'),
                            'duration' => '4 months',
                            'quantity' => '4 (one pouch per month)',
                            'inclusion' => 'included',
                        ]],
                    ],
                    $retestStep('Step 4: Retest the Gut Microbiome', 'Checkpoint · Around month 6'),
                    $maintenanceStep('Step 5: Maintain Gut Microbiome Health', 'Phase 3 · Ongoing'),
                ],
            ],

            // Plan B — Reset & Recover (AMR + Antimicrobic)
            [
                'key' => 'reset-recover',
                'name' => 'Reset & Recover',
                'trigger_description' => 'AMR + Antimicrobic recommended',
                'subscription' => [
                    'available' => true,
                    'price' => '£29.75 / month',
                    'full_price' => '£35 / month',
                    'saving_label' => '15% off',
                    'billing_note' => 'Save 15% vs buying separately · billed monthly',
                    'url' => 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/01KVFXHBGETW1GQ20HSN4F45C5',
                    'includes' => ['PetBiome AMR', 'Antimicrobic', 'PetBiome Maintenance'],
                ],
                'steps' => [
                    [
                        'type' => 'product',
                        'step_title' => 'Step 1: Microbiome Reset',
                        'stage_label' => 'Phase 1 · Months 1–3',
                        'products' => [[
                            'catalog_product_id' => $idFor('PetBiome AMR'),
                            'duration' => '3 months (12 weeks)',
                            'quantity' => '3 (one pouch per month)',
                            'inclusion' => 'included',
                        ]],
                    ],
                    [
                        'type' => 'prose',
                        'step_title' => 'Step 2: Implement Dietary Changes',
                        'stage_label' => 'Alongside Phase 1',
                        'body' => 'Reduce or remove highly processed foods, which can feed inflammatory bacteria. Where it suits Milo, move toward a simple, single-protein diet to ease the load on a reactive gut. Allow at least four weeks for any change to show in the microbiome, and avoid further diet changes during this window.',
                        'tip' => 'An omega-3 source such as oily fish can help support the skin and gut barrier while the inflammation settles — introduce it gradually.',
                    ],
                    [
                        'type' => 'product',
                        'step_title' => 'Step 3: Targeted Support',
                        'stage_label' => 'Phase 2 · Months 4–7',
                        'products' => [[
                            'catalog_product_id' => $idFor('Antimicrobic'),
                            'duration' => '4 months',
                            'quantity' => '4 (one pouch per month)',
                            'inclusion' => 'included',
                        ]],
                    ],
                    $retestStep('Step 4: Retest the Gut Microbiome', 'Checkpoint · Around month 6'),
                    $maintenanceStep('Step 5: Maintain Gut Microbiome Health', 'Phase 3 · Ongoing'),
                ],
            ],

            // Plan C — Maintain & Protect (all-green result)
            [
                'key' => 'maintain-protect',
                'name' => 'Maintain & Protect',
                'trigger_description' => 'Microbiome overview all green',
                'subscription' => [
                    'available' => true,
                    'price' => '£29.75 / month',
                    'full_price' => '£35 / month',
                    'saving_label' => '15% off',
                    'billing_note' => 'Save 15% vs buying separately · billed monthly',
                    'url' => 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/01KVFYG4BH3SYHTXTMR26Z8PM1',
                    'includes' => ['PetBiome Maintenance'],
                ],
                'steps' => [
                    $maintenanceStep('Step 1: Maintain Gut Microbiome Health', 'Ongoing'),
                    [
                        'type' => 'prose',
                        'step_title' => 'Step 2: Keep Supporting Habits Steady',
                        'stage_label' => 'Day to day',
                        'body' => 'With a balanced microbiome, consistency is what protects it. Keep Bella\'s diet steady, avoid unnecessary changes, and reintroduce anything new gradually. A yearly retest — or one after a course of antibiotics or a bout of illness — is the best way to catch any drift early.',
                        'tip' => 'Sudden diet switches and antibiotic courses are the most common causes of a balanced microbiome slipping. When either is unavoidable, a retest afterwards is worthwhile.',
                    ],
                ],
            ],

            // Plan D — Rebuild & Renew (FMT) — AMR + Gut Renew together in Step 1
            [
                'key' => 'rebuild-renew',
                'name' => 'Rebuild & Renew',
                'trigger_description' => 'FMT recommended',
                'subscription' => [
                    'available' => true,
                    'price' => '£132 / month',
                    'billing_note' => 'First 3 months, then £29.75/mo · save 15%',
                    'url' => 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/01KVFYJ4KGZ71REZYHS03KQF4Z',
                    'includes' => ['PetBiome AMR', 'Gut Renew', 'PetBiome Maintenance'],
                ],
                'steps' => [
                    [
                        'type' => 'product',
                        'step_title' => 'Step 1: Intensive Reset',
                        'stage_label' => 'Phase 1 · Months 1–3 · taken together',
                        'products' => [
                            [
                                'catalog_product_id' => $idFor('PetBiome AMR'),
                                'duration' => '3 months',
                                'quantity' => '3 (one pouch per month)',
                                'inclusion' => 'included',
                            ],
                            [
                                'catalog_product_id' => $idFor('Gut Renew'),
                                'duration' => '3 months',
                                'quantity' => '3 (one course per month)',
                                'inclusion' => 'included',
                            ],
                        ],
                    ],
                    [
                        'type' => 'prose',
                        'step_title' => 'Step 2: Implement Dietary Changes',
                        'stage_label' => 'Alongside Phase 1',
                        'body' => 'Diet plays a big part in whether new bacteria settle. Reduce highly processed foods and keep Rex\'s meals simple and consistent through the rebuild, so the gut environment supports the community Gut Renew is introducing. Avoid further diet changes during this window.',
                        'tip' => 'A small amount of daily bone broth is a gentle way to support a recovering gut — home-cooked works just as well as shop-bought.',
                    ],
                    $retestStep('Step 3: Retest the Gut Microbiome', 'Checkpoint · Month 3'),
                    // Plan D ending confirmed as Maintenance.
                    $maintenanceStep('Step 4: Maintain Gut Microbiome Health', 'Phase 2 · Ongoing'),
                ],
            ],
        ];
    }
}
