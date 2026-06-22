<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Filament\Resources\ReportResource;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 1: the plan-recommendation mapping is surfaced read-only in the admin.
 * These guard that what's SHOWN (planRecommendationRules / the explainer) stays
 * accurate to what actually HAPPENS (recommendPlanId), including FMT-first and
 * the no-match→null vs no-triggers→fallback distinction. Nothing behavioural
 * changed — recommendPlanId is exercised directly.
 */
class PlanRecommendationSurfacingTest extends TestCase
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

        // Seed the canonical config (Phase 2 data-driven mapping): the 4 plans
        // with their trigger conditions, match_priority and is_fallback.
        (new PlanSeeder())->run();
        // A plan that is NOT auto-recommended (no conditions, not fallback) — for
        // the "manual only" branch.
        Plan::create(['key' => 'custom-extra', 'name' => 'Custom Extra', 'enabled' => true, 'position' => 9, 'match_priority' => 1000]);

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'super_admin', 'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** The surfaced rules resolve to the SAME plan ids that recommendPlanId() picks. */
    public function test_surfaced_rules_match_actual_recommendPlanId(): void
    {
        $representativeTriggers = [
            'rebuild-renew' => ['FMT'],
            'reset-recover' => ['AMR', 'Antimicrobic'],
            'restore-rebalance' => ['AMR', 'Prebiotic'],
            'maintain-protect' => [],
        ];

        $rules = ReportResource::planRecommendationRules();

        // Order is the precedence order, numbered 1..N with no gaps.
        $this->assertSame([1, 2, 3, 4], array_column($rules, 'order'));

        foreach ($rules as $rule) {
            $expectedId = Plan::where('key', $rule['key'])->value('id');
            $this->assertSame(
                $expectedId,
                ReportResource::recommendPlanId($representativeTriggers[$rule['key']]),
                "surfaced rule #{$rule['order']} ({$rule['key']}) must match recommendPlanId",
            );
        }
    }

    /** FMT-first precedence: FMT wins even when a lower rule's triggers are also present. */
    public function test_fmt_takes_priority_as_surfaced(): void
    {
        // Surfaced: FMT is rule #1.
        $this->assertSame('rebuild-renew', ReportResource::planRecommendationRules()[0]['key']);

        // Actual: FMT + AMR + Prebiotic (which alone would be restore-rebalance) → rebuild-renew.
        $this->assertSame(
            Plan::where('key', 'rebuild-renew')->value('id'),
            ReportResource::recommendPlanId(['FMT', 'AMR', 'Prebiotic']),
        );
    }

    /** no-triggers→fallback is rule #4; fired-but-no-match → null (not the fallback). */
    public function test_fallback_vs_null_distinction(): void
    {
        // The fallback rule is surfaced as the "no triggers fire" case.
        $this->assertSame(
            'No triggers fire (default fallback)',
            ReportResource::planRecommendationRuleFor('maintain-protect')['condition'],
        );

        // Empty set → the fallback plan.
        $this->assertSame(
            Plan::where('key', 'maintain-protect')->value('id'),
            ReportResource::recommendPlanId([]),
        );

        // Fires a trigger but matches none of rules 1-3 → NO recommendation.
        $this->assertNull(ReportResource::recommendPlanId(['Prebiotic']));
    }

    public function test_rule_for_unknown_plan_is_null(): void
    {
        $this->assertNull(ReportResource::planRecommendationRuleFor('custom-extra'));
        $this->assertNull(ReportResource::planRecommendationRuleFor(null));
        $this->assertSame(1, ReportResource::planRecommendationRuleFor('rebuild-renew')['order']);
    }

    /** The Plans list surfaces each plan's condition + the "manual only" branch. */
    public function test_plans_list_shows_recommended_when_column(): void
    {
        Livewire::test(ListPlans::class)
            ->assertOk()
            ->assertSee('Auto-recommended when')
            ->assertSee('FMT trigger fires')
            ->assertSee('Not auto-recommended (manual only)');
    }

    /** The Settings → Trigger Rules tab shows the precedence explainer. */
    public function test_settings_shows_plan_recommendation_explainer(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('How plans are recommended')
            ->assertSee('first match wins')
            ->assertSee('FMT trigger fires');
    }

    /** Editing a plan persists its conditions AND enforces a single fallback. */
    public function test_editing_a_plan_persists_conditions_and_enforces_single_fallback(): void
    {
        $restore = Plan::where('key', 'restore-rebalance')->first();
        $maintain = Plan::where('key', 'maintain-protect')->first(); // the seeded fallback

        $this->assertFalse($restore->is_fallback);
        $this->assertTrue($maintain->is_fallback);

        Livewire::test(EditPlan::class, ['record' => $restore->getRouteKey()])
            ->assertFormSet(['is_fallback' => false])
            ->set('data.is_fallback', true)
            ->set('data.trigger_conditions', [['required_triggers' => ['AMR', 'FMT']]])
            ->call('save')
            ->assertHasNoFormErrors();

        // Conditions persisted (delete + recreate from the form state).
        $this->assertSame(
            [['AMR', 'FMT']],
            $restore->refresh()->triggerConditions->pluck('required_triggers')->all(),
        );

        // Single-fallback guard: marking this plan fallback demoted the old one.
        $this->assertTrue($restore->is_fallback);
        $this->assertFalse($maintain->refresh()->is_fallback);
    }
}
