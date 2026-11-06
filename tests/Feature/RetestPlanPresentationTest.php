<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retest plan presentation (step 7e): hide_commerce defaults true at the first-link moment
 * (prices/subscribe hidden) while staying toggle-able, and a factual plan-change line
 * renders in the plan section on web + PDF. Uses the real Teddy fixtures for the link freeze.
 */
class RetestPlanPresentationTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');
    }

    private function makeTest(Pet $pet, Client $client, string $fixture, string $relative): Test
    {
        Storage::disk('local')->put($relative, file_get_contents(base_path("tests/fixtures/retest/{$fixture}")));
        $parsed = (new CsvParserService)->parse(base_path("tests/fixtures/retest/{$fixture}"));

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-01-01',
            'csv_path' => $relative,
            'csv_data' => ['top_taxa' => $parsed['top_taxa'], 'insight_taxa' => $parsed['insight_taxa']],
            'phylum_data' => $parsed['phylum_totals'],
            'diversity_score' => $parsed['diversity_score'],
            'species_richness' => $parsed['species_richness'],
            'dysbiosis_score' => $parsed['dysbiosis_score'],
            'microbiome_classification' => $parsed['microbiome_classification'],
        ]);
    }

    private function makePlan(string $name): Plan
    {
        return Plan::create([
            'key' => 'p-'.uniqid(), 'name' => $name, 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/x',
        ]);
    }

    /** Attach a plan + one step + an available subscription snapshot so the plan section renders. */
    private function attachPlan(Report $report, Plan $plan): void
    {
        $report->update([
            'plan_id' => $plan->id,
            'subscription_snapshot' => [
                'available' => true, 'price' => '£30 / month', 'url' => 'https://loop.test/x', 'includes' => [],
            ],
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step 1', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);
    }

    /**
     * Seed an original + a linked retest with the given plan names; returns [original, retest].
     * The link freeze (retestFreezePayload) runs on the retest.
     */
    private function seedLinkedRetest(string $origPlan, string $retestPlan): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        $this->attachPlan($original, $this->makePlan($origPlan));
        $this->attachPlan($retest, $this->makePlan($retestPlan));

        // Link → first-time freeze (sets hide_commerce = true).
        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));

        return [$original->fresh(), $retest->fresh()];
    }

    private function web(Report $report): string
    {
        return view('report.show', ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'retestOf.plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    private function pdf(Report $report): string
    {
        return view('report.pdf', ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'retestOf.plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    public function test_linking_sets_hide_commerce_and_hides_subscribe_and_toggle_persists(): void
    {
        [, $retest] = $this->seedLinkedRetest('Plan A', 'Plan B');

        // Defaulted on at the first-link moment.
        $this->assertTrue($retest->fresh()->hide_commerce);

        // Subscribe panel / price not rendered while hidden.
        $web = $this->web($retest->fresh());
        $this->assertStringNotContainsString('Subscribe to', $web);
        $this->assertStringNotContainsString('£30 / month', $web);
        // The no-commerce reassurance box takes its place.
        $this->assertStringContainsString('Your Biome Balance Programme', $web);

        // Team toggles it back off → a re-save (same freeze path) must NOT re-force it.
        $retest->update(['hide_commerce' => false]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));
        $this->assertFalse($retest->fresh()->hide_commerce, 're-save must not re-force hide_commerce');

        $web2 = $this->web($retest->fresh());
        $this->assertStringContainsString('Subscribe to', $web2);
        $this->assertStringContainsString('£30 / month', $web2);
    }

    public function test_plan_change_line_updated_on_web_and_pdf(): void
    {
        [, $retest] = $this->seedLinkedRetest('Plan A', 'Plan B');

        $expected = 'Plan updated: Plan A → Plan B';
        $this->assertSame($expected, $retest->fresh()->planChangeLine());
        $this->assertStringContainsString($expected, $this->web($retest));
        $this->assertStringContainsString($expected, $this->pdf($retest));
    }

    public function test_plan_change_line_unchanged_when_plans_match(): void
    {
        [, $retest] = $this->seedLinkedRetest('Plan A', 'Plan A');

        $expected = 'Plan unchanged: Plan A';
        $this->assertSame($expected, $retest->fresh()->planChangeLine());
        $this->assertStringContainsString($expected, $this->web($retest));
        $this->assertStringContainsString($expected, $this->pdf($retest));
    }

    public function test_non_retest_report_is_untouched(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();
        $this->attachPlan($report, $this->makePlan('Plan A'));

        // hide_commerce untouched (stays false) and no plan-change line.
        $this->assertFalse($report->fresh()->hide_commerce);
        $this->assertNull($report->fresh()->planChangeLine());

        $web = $this->web($report);
        $this->assertStringNotContainsString('Plan updated', $web);
        $this->assertStringNotContainsString('Plan unchanged', $web);
        // Commerce still shows for a normal report.
        $this->assertStringContainsString('Subscribe to', $web);
    }
}
