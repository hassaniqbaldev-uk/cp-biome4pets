<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\CatalogProduct;
use App\Models\ProductRule;
use App\Models\User;
use App\Services\CsvParserService;
use Database\Seeders\ProductRuleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductRulesEngineTest extends TestCase
{
    protected CsvParserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('product_rules', function ($table) {
            $table->id();
            $table->string('trigger_name');
            $table->string('metric');
            $table->string('operator');
            $table->decimal('value', 12, 4);
            $table->decimal('value2', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('catalog_products', function ($table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('catalog_product_trigger', function ($table) {
            $table->id();
            $table->foreignId('catalog_product_id');
            $table->string('trigger');
            $table->timestamps();
        });

        $this->service = new CsvParserService();
    }

    /**
     * The canonical rule set, used as the oracle for equivalence. Mirrors
     * ProductRuleSeeder and CsvParserService's hardcoded fallback exactly:
     *   AMR          = Bacteroidetes outside [10, 30]
     *   Prebiotic    = Firmicutes < 18
     *   Antimicrobic = Bacteroidetes > 30
     *   FMT          = diversity < 1.6
     * (The retired "Biotic Boost" trigger and the old Fusobacteria/2.5 thresholds
     * are gone.)
     */
    private function hardcoded(array $phylum, float $diversity): array
    {
        $t = [];
        $b = $phylum['Bacteroidetes'] ?? 0;
        if ($b > 30 || $b < 10) {
            $t[] = 'AMR';
        }
        if (($phylum['Firmicutes'] ?? 0) < 18) {
            $t[] = 'Prebiotic';
        }
        if ($b > 30) {
            $t[] = 'Antimicrobic';
        }
        if ($diversity < 1.6) {
            $t[] = 'FMT';
        }

        return $t;
    }

    public static function knownInputs(): array
    {
        // Canonical rules: AMR = Bacteroidetes outside [10,30], Prebiotic =
        // Firmicutes < 18, Antimicrobic = Bacteroidetes > 30, FMT = diversity < 1.6.
        return [
            'fmt only (low diversity, balanced phyla)' => [['Bacteroidetes' => 20, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 30], 1.5],
            'all clear' => [['Bacteroidetes' => 20, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 10], 3.5],
            'amr + antimicrobic (high bacteroidetes)' => [['Bacteroidetes' => 45, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 10], 3.5],
            'amr low + prebiotic' => [['Bacteroidetes' => 5, 'Firmicutes' => 10, 'Proteobacteria' => 5, 'Fusobacteria' => 10], 3.5],
            'boundary exactly 30/18/1.6 (none fire)' => [['Bacteroidetes' => 30, 'Firmicutes' => 18, 'Proteobacteria' => 10, 'Fusobacteria' => 25], 1.6],
            'all fire (amr+antimicrobic+prebiotic+fmt)' => [['Bacteroidetes' => 50, 'Firmicutes' => 5, 'Proteobacteria' => 20, 'Fusobacteria' => 40], 1.0],
        ];
    }

    #[DataProvider('knownInputs')]
    public function test_seeded_rules_match_hardcoded_logic_exactly(array $phylum, float $diversity): void
    {
        (new ProductRuleSeeder())->run();

        $expected = $this->hardcoded($phylum, $diversity);
        $actual = $this->service->evaluateProductRules($phylum, $diversity);

        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_high_bacteroidetes_low_diversity_fires_amr_antimicrobic_and_fmt(): void
    {
        (new ProductRuleSeeder())->run();

        $fired = $this->service->evaluateProductRules(
            ['Bacteroidetes' => 35, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 30],
            1.5,
        );

        sort($fired);
        // Bacteroidetes 35 is > 30 (Antimicrobic) and outside [10,30] (AMR);
        // diversity 1.5 < 1.6 (FMT). Antimicrobic always co-fires AMR.
        $this->assertSame(['AMR', 'Antimicrobic', 'FMT'], $fired);
    }

    public function test_empty_table_falls_back_to_hardcoded(): void
    {
        // No rules seeded at all.
        $this->assertSame(0, ProductRule::count());

        $phylum = ['Bacteroidetes' => 45, 'Firmicutes' => 10, 'Proteobacteria' => 15, 'Fusobacteria' => 30];
        $fired = $this->service->evaluateProductRules($phylum, 1.5);

        $expected = $this->hardcoded($phylum, 1.5);
        sort($expected);
        sort($fired);
        $this->assertSame($expected, $fired);
        $this->assertSame(['AMR', 'Antimicrobic', 'FMT', 'Prebiotic'], $fired);
    }

    public function test_new_rule_fires_and_becomes_selectable_on_products(): void
    {
        (new ProductRuleSeeder())->run();

        ProductRule::create([
            'trigger_name' => 'GutShield',
            'metric' => 'Firmicutes',
            'operator' => 'gt',
            'value' => 40,
            'is_active' => true,
        ]);

        // Fires for matching input.
        $fired = $this->service->evaluateProductRules(
            ['Bacteroidetes' => 20, 'Firmicutes' => 50, 'Proteobacteria' => 5, 'Fusobacteria' => 10],
            3.5,
        );
        $this->assertContains('GutShield', $fired);

        // Becomes selectable on catalog products (shared dynamic list).
        $this->assertArrayHasKey('GutShield', ProductRule::triggerNameOptions());

        // Two-way link: a product tagged GutShield is matched by the same query
        // the report flow uses.
        $product = CatalogProduct::create(['name' => 'Gut Shield Chews', 'is_active' => true]);
        DB::table('catalog_product_trigger')->insert([
            'catalog_product_id' => $product->id,
            'trigger' => 'GutShield',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matchedIds = CatalogProduct::query()
            ->where('is_active', true)
            ->whereExists(fn ($q) => $q->from('catalog_product_trigger')
                ->whereColumn('catalog_product_id', 'catalog_products.id')
                ->whereIn('trigger', $fired))
            ->pluck('id')
            ->all();

        $this->assertContains($product->id, $matchedIds);
    }

    public function test_inactive_rule_does_not_fire(): void
    {
        (new ProductRuleSeeder())->run();
        ProductRule::where('trigger_name', 'FMT')->update(['is_active' => false]);

        // Bacteroidetes 35 fires Antimicrobic (and AMR); diversity 1.5 would fire
        // FMT, but FMT is disabled — so it must not appear.
        $fired = $this->service->evaluateProductRules(
            ['Bacteroidetes' => 35, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 30],
            1.5,
        );

        $this->assertContains('Antimicrobic', $fired);
        $this->assertNotContains('FMT', $fired);
    }

    /**
     * FMT boundary precision: the rule is strict `<` 1.6, so 1.59 fires and an
     * exact 1.60 does NOT. Phyla are balanced so FMT (diversity) is the only
     * trigger in play.
     */
    public function test_fmt_threshold_is_strict_below_1_point_6(): void
    {
        (new ProductRuleSeeder())->run();

        $phylum = ['Bacteroidetes' => 20, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 10];

        // 1.59 is below 1.6 → FMT recommended.
        $this->assertContains('FMT', $this->service->evaluateProductRules($phylum, 1.59));

        // Exactly 1.60 is not below 1.6 (strict <) → FMT NOT recommended.
        $this->assertNotContains('FMT', $this->service->evaluateProductRules($phylum, 1.60));

        // Just above stays off too.
        $this->assertNotContains('FMT', $this->service->evaluateProductRules($phylum, 1.61));
    }

    public function test_unknown_metric_is_skipped_not_crashed(): void
    {
        ProductRule::create([
            'trigger_name' => 'Bogus',
            'metric' => 'NonexistentMetric',
            'operator' => 'gt',
            'value' => 1,
            'is_active' => true,
        ]);

        // Does not throw; the bogus rule simply does not fire.
        $fired = $this->service->evaluateProductRules(
            ['Bacteroidetes' => 20, 'Firmicutes' => 25, 'Proteobacteria' => 5, 'Fusobacteria' => 10],
            3.5,
        );

        $this->assertNotContains('Bogus', $fired);
    }

    public function test_settings_trigger_rules_tab_persists_and_validates(): void
    {
        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        $this->actingAs(new User(['name' => 'Admin', 'email' => 'admin@cp.agency']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 'between'/'outside' require value2 -> validation error.
        Livewire::test(Settings::class)
            ->set('data.product_rules', [
                ['id' => null, 'trigger_name' => 'X', 'metric' => 'Firmicutes', 'operator' => 'between', 'value' => 10, 'value2' => null, 'is_active' => true],
            ])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(0, ProductRule::count());

        // Valid rule persists.
        Livewire::test(Settings::class)
            ->set('data.product_rules', [
                ['id' => null, 'trigger_name' => 'NewOne', 'metric' => 'Firmicutes', 'operator' => 'gt', 'value' => 40, 'value2' => null, 'is_active' => true],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, ProductRule::where('trigger_name', 'NewOne')->count());
    }
}
