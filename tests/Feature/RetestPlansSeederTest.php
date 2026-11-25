<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\RetestPlanRules;
use Database\Seeders\CatalogProductSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RetestPlansSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deploy-safety guard for RetestPlansSeeder: it adds ONLY the three retest-only plans,
 * insert-if-absent by key, never duplicating and never updating/touching the four base
 * plans (which may have been edited in the admin on live). A full DatabaseSeeder still
 * yields all seven plans, and the retest rules resolve every plan key they target.
 */
class RetestPlansSeederTest extends TestCase
{
    private const RETEST_KEYS = ['retest-amr-only', 'retest-prebiotic-only', 'retest-spirochaetes'];

    private const BASE_KEYS = ['restore-rebalance', 'reset-recover', 'maintain-protect', 'rebuild-renew'];

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

    /** key => [name, updated_at, step count] snapshot for comparing "unchanged". */
    private function snapshot(array $keys): array
    {
        return Plan::whereIn('key', $keys)->get()->mapWithKeys(fn (Plan $p): array => [
            $p->key => [
                'name' => $p->name,
                'updated_at' => (string) $p->updated_at,
                'steps' => $p->steps()->count(),
                'products' => $p->steps()->withCount('products')->get()->sum('products_count'),
            ],
        ])->all();
    }

    public function test_adds_exactly_the_three_retest_plans_and_leaves_base_plans_untouched(): void
    {
        $this->seed([CatalogProductSeeder::class, PlanSeeder::class]);

        $this->assertSame(4, Plan::count(), 'PlanSeeder seeds only the four base plans');
        foreach (self::RETEST_KEYS as $key) {
            $this->assertFalse(Plan::where('key', $key)->exists(), "{$key} should not exist before RetestPlansSeeder");
        }
        $before = $this->snapshot(self::BASE_KEYS);

        (new RetestPlansSeeder)->run();

        // Exactly the three new plans added; nothing else.
        $this->assertSame(7, Plan::count());
        foreach (self::RETEST_KEYS as $key) {
            $this->assertTrue(Plan::where('key', $key)->exists(), "{$key} should now exist");
        }

        // The four base plans are byte-for-byte unchanged (name, updated_at, step/product counts).
        $this->assertSame($before, $this->snapshot(self::BASE_KEYS));
    }

    public function test_running_twice_does_not_duplicate_or_mutate(): void
    {
        $this->seed([CatalogProductSeeder::class, PlanSeeder::class]);
        (new RetestPlansSeeder)->run();

        $afterFirst = $this->snapshot(array_merge(self::BASE_KEYS, self::RETEST_KEYS));
        $this->assertSame(7, Plan::count());

        (new RetestPlansSeeder)->run(); // second run

        $this->assertSame(7, Plan::count(), 'second run must not duplicate');
        foreach (self::RETEST_KEYS as $key) {
            $this->assertSame(1, Plan::where('key', $key)->count(), "{$key} must be unique");
        }
        // All seven rows unchanged by the second run.
        $this->assertSame($afterFirst, $this->snapshot(array_merge(self::BASE_KEYS, self::RETEST_KEYS)));
    }

    public function test_never_touches_an_already_present_retest_plan(): void
    {
        $this->seed([CatalogProductSeeder::class, PlanSeeder::class]);

        // Simulate a pre-existing / admin-edited retest plan: custom name + a single
        // custom step that differs from the seeder default (2 steps).
        $edited = Plan::create(['key' => 'retest-amr-only', 'name' => 'EDITED IN ADMIN', 'enabled' => true, 'is_fallback' => false]);
        $edited->steps()->create(['type' => 'prose', 'step_title' => 'CUSTOM STEP', 'position' => 0]);

        (new RetestPlansSeeder)->run();

        $fresh = Plan::where('key', 'retest-amr-only')->get();
        $this->assertCount(1, $fresh, 'no duplicate of the existing key');
        $this->assertSame('EDITED IN ADMIN', $fresh->first()->name, 'existing plan name untouched');
        $this->assertSame(1, $fresh->first()->steps()->count(), 'existing plan steps not rebuilt');

        // The other two retest plans are still created fresh.
        $this->assertTrue(Plan::where('key', 'retest-prebiotic-only')->exists());
        $this->assertTrue(Plan::where('key', 'retest-spirochaetes')->exists());
    }

    public function test_full_database_seed_yields_all_seven_plans_and_rules_resolve(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (array_merge(self::BASE_KEYS, self::RETEST_KEYS) as $key) {
            $this->assertTrue(Plan::where('key', $key)->exists(), "plan {$key} missing after full seed");
        }

        // The three retest plans carry their steps (not empty).
        foreach (self::RETEST_KEYS as $key) {
            $this->assertGreaterThan(0, Plan::where('key', $key)->firstOrFail()->steps()->count(), "{$key} has no steps");
        }

        // Every plan key the retest rules target resolves to a seeded plan.
        foreach (RetestPlanRules::RETEST_RULES as $rule) {
            $this->assertTrue(
                Plan::where('key', $rule['plan_key'])->exists(),
                "rule {$rule['key']} targets unresolved plan {$rule['plan_key']}",
            );
        }

        // Retest plans stay invisible to first-test matching.
        foreach (self::RETEST_KEYS as $key) {
            $plan = Plan::where('key', $key)->firstOrFail();
            $this->assertFalse((bool) $plan->is_fallback);
            $this->assertSame(0, $plan->triggerConditions()->count());
        }
    }
}
