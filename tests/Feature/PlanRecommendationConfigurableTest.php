<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\Plan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2: the matcher is data-driven. These prove the new configurable path —
 * editing the config (conditions / priority / fallback) changes recommendations,
 * AND-within-row / OR-across-rows semantics, priority precedence + deterministic
 * tiebreak, and the fallback vs null distinction.
 */
class PlanRecommendationConfigurableTest extends TestCase
{
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
        Artisan::call('migrate', ['--force' => true]);
    }

    private function plan(string $key, int $priority, array $conditions = [], bool $fallback = false): Plan
    {
        $plan = Plan::create([
            'key' => $key, 'name' => ucfirst($key), 'enabled' => true,
            'match_priority' => $priority, 'is_fallback' => $fallback,
        ]);

        foreach ($conditions as $i => $set) {
            $plan->triggerConditions()->create(['position' => $i, 'required_triggers' => $set]);
        }

        return $plan;
    }

    /** A condition added purely in config changes the recommendation. */
    public function test_custom_condition_drives_a_new_recommendation(): void
    {
        $shield = $this->plan('gut-shield', 5, [['GutShield']]);

        // The custom trigger now selects the custom plan — no code change.
        $this->assertSame($shield->id, ReportResource::recommendPlanId(['GutShield']));
        // An unrelated trigger does not.
        $this->assertNull(ReportResource::recommendPlanId(['FMT']));
    }

    /** AND within a row: every listed trigger must fire. */
    public function test_and_within_a_condition_row(): void
    {
        $plan = $this->plan('combo', 5, [['AMR', 'Prebiotic']]);

        $this->assertSame($plan->id, ReportResource::recommendPlanId(['AMR', 'Prebiotic', 'Extra']));
        $this->assertNull(ReportResource::recommendPlanId(['AMR']));        // missing Prebiotic
        $this->assertNull(ReportResource::recommendPlanId(['Prebiotic']));  // missing AMR
    }

    /** OR across rows: any satisfied row selects the plan. */
    public function test_or_across_condition_rows(): void
    {
        $plan = $this->plan('either', 5, [['AMR', 'Antimicrobic'], ['FMT']]);

        $this->assertSame($plan->id, ReportResource::recommendPlanId(['FMT']));
        $this->assertSame($plan->id, ReportResource::recommendPlanId(['AMR', 'Antimicrobic']));
        $this->assertNull(ReportResource::recommendPlanId(['AMR']));
    }

    /** Lower match_priority wins when two plans both match. */
    public function test_priority_decides_between_two_matching_plans(): void
    {
        $high = $this->plan('high-prio', 1, [['AMR']]);
        $low = $this->plan('low-prio', 9, [['AMR']]);

        $this->assertSame($high->id, ReportResource::recommendPlanId(['AMR']));

        // Flip the priorities → the other plan wins.
        $high->update(['match_priority' => 9]);
        $low->update(['match_priority' => 1]);
        $this->assertSame($low->id, ReportResource::recommendPlanId(['AMR']));
    }

    /** Equal priority → deterministic tiebreak by id (lower id wins). */
    public function test_equal_priority_breaks_ties_by_id(): void
    {
        $first = $this->plan('first', 5, [['AMR']]);
        $second = $this->plan('second', 5, [['AMR']]);

        $this->assertLessThan($second->id, $first->id);
        $this->assertSame($first->id, ReportResource::recommendPlanId(['AMR']));
    }

    /** Fallback only on empty triggers; fired-but-no-match → null. */
    public function test_fallback_only_when_nothing_fires(): void
    {
        $this->plan('matcher', 5, [['AMR']]);
        $fallback = $this->plan('safety-net', 100, [], fallback: true);

        $this->assertSame($fallback->id, ReportResource::recommendPlanId([]));        // nothing fired
        $this->assertNull(ReportResource::recommendPlanId(['Unmatched']));            // fired, no match
        $this->assertNotSame($fallback->id, ReportResource::recommendPlanId(['AMR'])); // a real match wins
    }

    /** A disabled plan is never recommended. */
    public function test_disabled_plan_is_skipped(): void
    {
        $plan = $this->plan('disabled-one', 5, [['AMR']]);
        $plan->update(['enabled' => false]);

        $this->assertNull(ReportResource::recommendPlanId(['AMR']));
    }
}
