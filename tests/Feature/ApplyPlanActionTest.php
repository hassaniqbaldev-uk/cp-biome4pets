<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Services\OpenAiService;
use Database\Seeders\CatalogProductSeeder;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression guard for the production crash "Undefined variable $steps" at the
 * apply_plan action: the Stage-3 variant refactor moved the instantiated steps into
 * PlanInstantiation::build()'s result ($instantiation['steps']) but the success
 * notification still said count($steps). The existing PlanVariantPathConsistencyTest
 * only string-matches the source, so it never RAN the closure and missed it.
 *
 * This test mounts the real EditReport page and executes the apply_plan action
 * closure end-to-end with has_copy=true (the branch that referenced $steps), so any
 * undefined/stale variable in that closure fails the suite instead of reaching prod.
 */
class ApplyPlanActionTest extends TestCase
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

        // Real catalogue + plans (with steps/products) so apply_plan instantiates a
        // genuine plan structure through PlanInstantiation::build().
        (new CatalogProductSeeder)->run();
        (new PlanSeeder)->run();

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Stub the paid OpenAI call: a non-empty intro makes validatePlanCopy report
        // has_copy=true, which is exactly the branch that referenced $steps (line 852).
        // The action resolves OpenAiService from the container, so this fake is used.
        $this->app->instance(OpenAiService::class, new class extends OpenAiService
        {
            public function generatePlanCopy(array $petFindings, array $planScaffold, ?int $reportId = null): array
            {
                return ['intro' => 'Generated plan intro for the test pet.', 'steps' => []];
            }
        });
    }

    private function makeReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => 'draft',
        ]);
    }

    /**
     * Mount EditReport, pick a plan that has product steps, and run the REAL
     * apply_plan closure. Asserts it set steps + the subscription_snapshot, and that
     * the success-notification branch (count($instantiation['steps'])) ran without an
     * undefined-variable error.
     */
    public function test_apply_plan_runs_end_to_end_and_sets_steps_and_snapshot(): void
    {
        // "Restore & Rebalance" has standard AMR + other products in its steps.
        $plan = Plan::with('steps.products')->where('name', 'Restore & Rebalance')->firstOrFail();
        $this->assertNotEmpty($plan->steps, 'Test fixture plan should have steps');

        $report = $this->makeReport();

        $component = Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->set('data.plan_id', $plan->id);

        // The action lives inside a Forms\Components\Actions block; its container key is
        // the dotted statePath `data.apply_planAction`. Resolve it off the live form and
        // ->call() it — same closure, same injected $get/$set, run for real.
        $action = $component->instance()
            ->getCachedForms()['form']
            ->getComponent('data.apply_planAction')
            ->getAction('apply_plan');

        $action->call();

        $component->assertHasNoFormErrors();

        // Steps were instantiated into form state.
        $steps = $component->get('data.steps');
        $this->assertIsArray($steps);
        $this->assertNotEmpty($steps, 'apply_plan should populate the steps repeater');
        $this->assertCount($plan->steps->count(), $steps);

        // The subscription_snapshot was built through the variant-aware seam.
        $snapshot = $component->get('data.subscription_snapshot');
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('url', $snapshot);
        $this->assertArrayHasKey('includes', $snapshot);
        // No pet flags / no seeded variant → base resolution (variant key is null).
        $this->assertArrayHasKey('variant', $snapshot);
        $this->assertNull($snapshot['variant']);
        $this->assertSame($plan->subscription_url, $snapshot['url']);

        // The generated intro from the stubbed copy flowed through.
        $this->assertSame('Generated plan intro for the test pet.', $component->get('data.plan_intro'));
    }
}
