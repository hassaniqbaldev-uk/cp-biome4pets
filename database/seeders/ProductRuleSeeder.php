<?php

namespace Database\Seeders;

use App\Models\ProductRule;
use Illuminate\Database\Seeder;

class ProductRuleSeeder extends Seeder
{
    /**
     * The canonical trigger rule set. Trigger names are spelled exactly as below
     * because product tags (catalog_product_trigger.trigger) match on these
     * strings:
     *   AMR          = Bacteroidetes   outside [10, 30]   (> 30 OR < 10)
     *   Prebiotic    = Firmicutes      < 18
     *   Antimicrobic = Bacteroidetes   > 30
     *   FMT          = diversity_score < 1.6
     * 'Biotic Boost' is intentionally retired — no real product serves it, so
     * its rule is not seeded.
     */
    public const RULES = [
        ['trigger_name' => 'AMR', 'metric' => 'Bacteroidetes', 'operator' => 'outside', 'value' => 10, 'value2' => 30],
        ['trigger_name' => 'Prebiotic', 'metric' => 'Firmicutes', 'operator' => 'lt', 'value' => 18, 'value2' => null],
        ['trigger_name' => 'Antimicrobic', 'metric' => 'Bacteroidetes', 'operator' => 'gt', 'value' => 30, 'value2' => null],
        ['trigger_name' => 'FMT', 'metric' => 'diversity_score', 'operator' => 'lt', 'value' => 1.6, 'value2' => null],
    ];

    /**
     * Idempotent: clear product_rules and insert exactly the canonical set, so
     * re-running can never leave stale/duplicate rows (e.g. the old Antimicrobic
     * = Fusobacteria > 25 row) behind.
     */
    public function run(): void
    {
        ProductRule::query()->delete();

        foreach (self::RULES as $rule) {
            ProductRule::create($rule + ['is_active' => true]);
        }
    }
}
