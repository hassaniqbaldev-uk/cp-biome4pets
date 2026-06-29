<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\CatalogProduct;
use App\Models\Plan;
use App\Models\PlanVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stage 5: the plan-builder "Condition Variants" UI. Admins can create/edit a
 * variant (condition + link override + product swaps with dosage) on a plan; it
 * persists + reloads via the same delete-and-recreate the page uses for steps. A
 * plan with no variants is unchanged; a duplicate condition / no-op swap is blocked.
 */
class PlanVariantBuilderTest extends TestCase
{
    private CatalogProduct $amr;

    private CatalogProduct $amrRf;

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

        $this->amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $this->amrRf = CatalogProduct::create(['name' => 'AMR Rosemary-Free', 'price' => 36, 'is_active' => true]);

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'super_admin', 'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Form `steps` state with a single AMR product step (so a swap's "from" is valid). */
    private function amrStep(): array
    {
        return [[
            'type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1',
            'products' => [[
                'catalog_product_id' => $this->amr->id, 'duration' => '3 months',
                'quantity' => '3', 'dose' => 'Base dose', 'inclusion' => 'included',
            ]],
        ]];
    }

    private function sensitiveVariantRow(array $overrides = []): array
    {
        return array_merge([
            'condition' => PlanVariant::CONDITION_SENSITIVE,
            'enabled' => true,
            'subscription_url' => 'https://loop.test/checkout/SENSITIVE',
            'product_overrides' => [[
                'from_catalog_product_id' => $this->amr->id,
                'to_catalog_product_id' => $this->amrRf->id,
                'dose' => 'Sensitive dose', 'quantity' => '3 (RF)', 'duration' => '3 months (RF)',
            ]],
        ], $overrides);
    }

    /** A persisted plan with one AMR step (for edit-side tests). */
    private function makePlanWithAmrStep(string $key = 'restore-rebalance'): Plan
    {
        $plan = Plan::create([
            'key' => $key, 'name' => 'Restore & Rebalance', 'enabled' => true, 'match_priority' => 3,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/checkout/BASE',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $this->amr->id, 'inclusion' => 'included', 'position' => 0]);

        return $plan;
    }

    // ── Create ───────────────────────────────────────────────────────────────
    public function test_create_persists_a_variant_with_link_override_and_product_swap(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'match_priority' => 3,
                'subscription_url' => 'https://loop.test/checkout/BASE',
                'steps' => $this->amrStep(),
                'variants' => [$this->sensitiveVariantRow()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::where('key', 'restore-rebalance')->firstOrFail();
        $this->assertCount(1, $plan->variants);

        $variant = $plan->variants->first();
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $variant->condition);
        $this->assertTrue($variant->enabled);
        $this->assertSame('https://loop.test/checkout/SENSITIVE', $variant->subscription_url);

        $this->assertCount(1, $variant->productOverrides);
        $override = $variant->productOverrides->first();
        $this->assertSame($this->amr->id, $override->from_catalog_product_id);
        $this->assertSame($this->amrRf->id, $override->to_catalog_product_id);
        $this->assertSame('Sensitive dose', $override->dose);
        $this->assertSame('3 (RF)', $override->quantity);
        $this->assertSame('3 months (RF)', $override->duration);
    }

    public function test_blank_link_override_is_stored_as_null_to_inherit_base(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'key' => 'reset-recover', 'name' => 'Reset & Recover', 'match_priority' => 2,
                'subscription_url' => 'https://loop.test/checkout/BASE',
                'steps' => $this->amrStep(),
                'variants' => [$this->sensitiveVariantRow(['subscription_url' => null])],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variant = Plan::where('key', 'reset-recover')->firstOrFail()->variants->first();
        $this->assertNull($variant->subscription_url);   // inherits base at resolution time
    }

    // ── Edit: reload + persist + delete ──────────────────────────────────────
    public function test_existing_variant_hydrates_into_the_edit_form(): void
    {
        $plan = $this->makePlanWithAmrStep();
        $variant = $plan->variants()->create(['condition' => PlanVariant::CONDITION_SENSITIVE, 'subscription_url' => 'https://loop.test/checkout/SENSITIVE', 'enabled' => true]);
        $variant->productOverrides()->create(['from_catalog_product_id' => $this->amr->id, 'to_catalog_product_id' => $this->amrRf->id, 'dose' => 'Sensitive dose']);

        $data = Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])->get('data');

        $rows = array_values($data['variants']);
        $this->assertCount(1, $rows);
        $this->assertSame(PlanVariant::CONDITION_SENSITIVE, $rows[0]['condition']);
        $this->assertSame('https://loop.test/checkout/SENSITIVE', $rows[0]['subscription_url']);

        $swaps = array_values($rows[0]['product_overrides']);
        $this->assertSame($this->amr->id, $swaps[0]['from_catalog_product_id']);
        $this->assertSame($this->amrRf->id, $swaps[0]['to_catalog_product_id']);
        $this->assertSame('Sensitive dose', $swaps[0]['dose']);
    }

    public function test_edit_can_add_a_variant_to_an_existing_plan(): void
    {
        $plan = $this->makePlanWithAmrStep();

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->set('data.variants', [$this->sensitiveVariantRow()])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertCount(1, $plan->variants);
        $this->assertSame('https://loop.test/checkout/SENSITIVE', $plan->variants->first()->subscription_url);
    }

    public function test_deleting_a_variant_row_removes_it_and_its_overrides_on_save(): void
    {
        $plan = $this->makePlanWithAmrStep();
        $variant = $plan->variants()->create(['condition' => PlanVariant::CONDITION_SENSITIVE, 'enabled' => true]);
        $variant->productOverrides()->create(['from_catalog_product_id' => $this->amr->id, 'to_catalog_product_id' => $this->amrRf->id]);

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->set('data.variants', [])     // removed all variant rows
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $plan->refresh()->variants);
        // The override is gone too (cascade).
        $this->assertSame(0, DB::table('plan_variant_product_overrides')->count());
    }

    // ── Validation ───────────────────────────────────────────────────────────
    public function test_duplicate_condition_is_blocked_with_a_friendly_notification(): void
    {
        $plan = $this->makePlanWithAmrStep();

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->set('data.variants', [$this->sensitiveVariantRow(), $this->sensitiveVariantRow()])
            ->call('save')
            ->assertNotified('Check the condition variants');

        // Halted before persisting → no variants written.
        $this->assertCount(0, $plan->refresh()->variants);
    }

    public function test_swap_from_product_not_in_plan_steps_is_blocked(): void
    {
        $plan = $this->makePlanWithAmrStep();
        $other = CatalogProduct::create(['name' => 'Unrelated Product', 'price' => 10, 'is_active' => true]);

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->set('data.variants', [$this->sensitiveVariantRow([
                'product_overrides' => [[
                    'from_catalog_product_id' => $other->id,   // NOT used in the plan's steps
                    'to_catalog_product_id' => $this->amrRf->id,
                ]],
            ])])
            ->call('save')
            ->assertNotified('Check the condition variants');

        $this->assertCount(0, $plan->refresh()->variants);
    }

    // ── Preservation ─────────────────────────────────────────────────────────
    public function test_plan_with_no_variants_saves_normally_and_has_none(): void
    {
        $plan = $this->makePlanWithAmrStep();

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $plan->refresh()->variants);
        // Steps still intact (existing behaviour unchanged).
        $this->assertCount(1, $plan->steps);
    }
}
