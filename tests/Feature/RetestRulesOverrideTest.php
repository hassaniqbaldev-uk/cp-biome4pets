<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Support\ReportGeneration;
use Database\Seeders\CatalogProductSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RetestPlansSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Integration tests for the step-2b retest override: the full chain of
 * ReportGeneration::retestPlanOverride / applyRetestPlanOverride resolving each retest
 * rule to its seeded target plan (by key), the precedence order, the FMT delta rule, and
 * the guarantees that first tests are untouched and the three new retest-only plans are
 * invisible to first-test matching. Real plans + products are seeded into sqlite; the
 * retest's CSV is served from a faked local disk.
 */
class RetestRulesOverrideTest extends TestCase
{
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

        // Full plan + product catalogue (the override resolves plans by key).
        $this->seed([CatalogProductSeeder::class, PlanSeeder::class, RetestPlansSeeder::class]);

        Storage::fake('local');
    }

    /** CSV text with the given rows (rank cols + %_hits), full lab header. */
    private function csv(array $rows): string
    {
        $out = "Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits\n";
        foreach ($rows as $r) {
            $out .= implode(',', [
                'Bacteria', $r['phylum'] ?? '', $r['class'] ?? '', $r['order'] ?? '',
                $r['family'] ?? '', $r['genus'] ?? '', $r['species'] ?? '',
                $r['num_hits'] ?? 100, $r['pct'] ?? 0,
            ])."\n";
        }

        return $out;
    }

    /**
     * A retest report whose own Test carries a CSV (on the faked disk) built from $rows,
     * optionally with a frozen comparison, plus a pre-attached CSV-trigger product (the
     * thing a firing override must detach).
     */
    private function makeRetest(array $rows, ?array $comparison = null, bool $asRetest = true): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $rel = 'tests/'.uniqid('csv_').'.csv';
        Storage::disk('local')->put($rel, $this->csv($rows));
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'order_id' => 'ORD'.uniqid(), 'csv_path' => $rel,
        ]);

        $original = $asRetest
            ? Report::create(['client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'published'])
            : null;

        $report = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'test_id' => $test->id,
            'status' => 'draft',
            'retest_of_report_id' => $original?->id,
            'retest_comparison' => $comparison,
        ]);

        $product = CatalogProduct::create(['name' => 'Trigger AMR '.uniqid(), 'is_active' => true]);
        $report->catalogProducts()->attach($product->id, ['position' => 0]);

        return $report->fresh();
    }

    private function firingFmtComparison(): array
    {
        return [
            'overview' => [
                'original' => ['diversity' => 3.16, 'species_richness' => 463],
                'retest' => ['diversity' => 2.02, 'species_richness' => 215],
            ],
            'taxa' => [],
        ];
    }

    public function test_each_rule_resolves_the_override_to_its_target_plan(): void
    {
        $cases = [
            ['rows' => [['phylum' => 'Spirochaetes', 'pct' => 3.0]], 'plan' => 'retest-spirochaetes', 'code' => 'retest_spirochaetes'],
            ['rows' => [['genus' => 'Escherichia/Shigella', 'pct' => 2.5]], 'plan' => 'retest-amr-only', 'code' => 'retest_escherichia_shigella'],
            ['rows' => [['genus' => 'Campylobacter', 'pct' => 2.5]], 'plan' => 'retest-amr-only', 'code' => 'retest_pathogens'],
            ['rows' => [['genus' => 'Enterococcus', 'pct' => 6.0]], 'plan' => 'reset-recover', 'code' => 'retest_enterococcus'],
            ['rows' => [['family' => 'Erysipelotrichaceae', 'pct' => 3.0]], 'plan' => 'retest-prebiotic-only', 'code' => 'retest_erysipelotrichaceae'],
            ['rows' => [['genus' => 'Sutterella', 'pct' => 6.0]], 'plan' => 'maintain-protect', 'code' => 'retest_sutterella'],
            ['rows' => [['genus' => 'Flavonifractor', 'pct' => 2.5]], 'plan' => 'maintain-protect', 'code' => 'retest_flavonifactor'],
        ];

        foreach ($cases as $c) {
            $retest = $this->makeRetest($c['rows']);
            $expectedPlan = Plan::where('key', $c['plan'])->firstOrFail();

            $override = ReportGeneration::applyRetestPlanOverride($retest);

            $this->assertNotNull($override, "{$c['code']} should fire");
            $fresh = $retest->fresh();
            $this->assertSame($expectedPlan->id, $fresh->plan_id, "{$c['code']} → {$c['plan']}");
            $this->assertSame($c['code'], $fresh->recommendation_reason['code']);
            // The override fully replaces the standard selection: trigger products detached.
            $this->assertSame(0, $fresh->catalogProducts()->count());
        }
    }

    public function test_fmt_delta_rule_resolves_rebuild_renew(): void
    {
        // Low taxa (no threshold fires), but the comparison meets the FMT delta.
        $retest = $this->makeRetest([['phylum' => 'Firmicutes', 'pct' => 30]], $this->firingFmtComparison());

        $override = ReportGeneration::applyRetestPlanOverride($retest);

        $this->assertNotNull($override);
        $fresh = $retest->fresh();
        $this->assertSame(Plan::where('key', 'rebuild-renew')->value('id'), $fresh->plan_id);
        $this->assertSame('retest_fmt', $fresh->recommendation_reason['code']);
    }

    public function test_precedence_spirochaetes_wins_over_sutterella(): void
    {
        $retest = $this->makeRetest([
            ['phylum' => 'Spirochaetes', 'pct' => 3.0],
            ['genus' => 'Sutterella', 'pct' => 6.0],
        ]);

        ReportGeneration::applyRetestPlanOverride($retest);

        $fresh = $retest->fresh();
        $this->assertSame(Plan::where('key', 'retest-spirochaetes')->value('id'), $fresh->plan_id);
        $this->assertSame('retest_spirochaetes', $fresh->recommendation_reason['code']);
    }

    public function test_no_rule_fires_leaves_report_untouched(): void
    {
        $flat = [
            'overview' => [
                'original' => ['diversity' => 3.16, 'species_richness' => 463],
                'retest' => ['diversity' => 3.10, 'species_richness' => 450],
            ],
        ];
        $retest = $this->makeRetest([['phylum' => 'Spirochaetes', 'pct' => 1.0]], $flat);

        $this->assertNull(ReportGeneration::applyRetestPlanOverride($retest));
        $fresh = $retest->fresh();
        $this->assertNull($fresh->plan_id);                       // standard selection left to run
        $this->assertNull($fresh->recommendation_reason);
        $this->assertSame(1, $fresh->catalogProducts()->count()); // trigger product still attached
    }

    public function test_first_test_with_a_firing_csv_is_never_considered(): void
    {
        // Same winning Spirochaetes CSV, but NOT a retest (no retest_of_report_id).
        $report = $this->makeRetest([['phylum' => 'Spirochaetes', 'pct' => 3.0]], null, asRetest: false);

        $this->assertNull(ReportGeneration::retestPlanOverride($report));
        $this->assertNull(ReportGeneration::applyRetestPlanOverride($report));

        $fresh = $report->fresh();
        $this->assertNull($fresh->plan_id);
        $this->assertNull($fresh->recommendation_reason);
        $this->assertSame(1, $fresh->catalogProducts()->count());
    }

    public function test_new_retest_plans_are_invisible_to_first_test_matching(): void
    {
        $retestKeys = ['retest-amr-only', 'retest-prebiotic-only', 'retest-spirochaetes'];

        // All three exist, are non-fallback, and carry no trigger conditions.
        foreach ($retestKeys as $key) {
            $plan = Plan::where('key', $key)->firstOrFail();
            $this->assertFalse((bool) $plan->is_fallback, "{$key} must not be a fallback");
            $this->assertSame(0, $plan->triggerConditions()->count(), "{$key} must have no trigger conditions");
        }

        $retestPlanIds = Plan::whereIn('key', $retestKeys)->pluck('id')->all();

        // No triggers → the fallback (Maintain & Protect), never a retest plan.
        $noTrigger = ReportResource::recommendPlanWithReason([], 'Stable');
        $this->assertSame(Plan::where('key', 'maintain-protect')->value('id'), $noTrigger['plan_id']);

        // Various first-test trigger sets never resolve to a retest-only plan.
        foreach ([['FMT'], ['AMR', 'Antimicrobic'], ['AMR', 'Prebiotic'], ['Prebiotic'], ['AMR']] as $triggers) {
            $sel = ReportResource::recommendPlanWithReason($triggers, 'Unstable');
            $this->assertNotContains($sel['plan_id'], $retestPlanIds, 'triggers '.implode(',', $triggers).' must not pick a retest plan');
        }
    }
}
