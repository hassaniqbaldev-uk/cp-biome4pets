<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use Database\Seeders\CatalogProductSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RetestPlansSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART A + retest plan-copy end-to-end: a firing retest (Spirochaetes > 2.5) built through
 * the normal retest pipeline must have its FORCED plan (Spirochaetes Reset) materialised —
 * steps + products visible on web AND pdf, prices hidden, trigger products detached, the
 * retest_review flag set, AND the materialised steps carrying REAL per-pet copy (generated
 * through the same OpenAiService::generatePlanCopy → validatePlanCopy guardrail a first-test
 * report uses), not the placeholder seed — all WITHOUT a manual "Apply plan" click.
 */
class RetestOverridePlanRenderTest extends TestCase
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
        $this->seed([CatalogProductSeeder::class, PlanSeeder::class, RetestPlansSeeder::class]);
        Storage::fake('local');

        // Stub the paid plan-copy call: echo the scaffold's factual fields back verbatim
        // (so validatePlanCopy logs no drift) and fill the copy slots with markers, so the
        // retest build generates real per-pet copy exactly as a first-test report would.
        $this->app->instance(OpenAiService::class, $this->fakeAi());
    }

    private function fakeAi(bool $drift = false): OpenAiService
    {
        return new class($drift) extends OpenAiService
        {
            public function __construct(private bool $drift)
            {
            }

            public function generatePlanCopy(array $petFindings, array $planScaffold, ?int $reportId = null): array
            {
                $steps = [];
                foreach ($planScaffold['steps'] as $s) {
                    if (($s['type'] ?? '') === 'prose') {
                        $steps[] = [
                            'type' => 'prose', 'step_title' => $s['step_title'], 'stage_label' => $s['stage_label'],
                            'body' => 'BODY-'.($s['step_title'] ?? ''), 'tip' => 'TIP-'.($s['step_title'] ?? ''),
                        ];

                        continue;
                    }
                    $products = array_map(function (array $p): array {
                        $p['how_it_helps'] = 'HOW-'.($p['name'] ?? '');
                        if ($this->drift) {
                            // Try to hallucinate factual fields — validatePlanCopy must reject these.
                            $p['name'] = 'HALLUCINATED PRODUCT';
                            $p['duration'] = '999 WRONG';
                        }

                        return $p;
                    }, $s['products']);
                    $steps[] = [
                        'type' => 'product', 'step_title' => $s['step_title'], 'stage_label' => $s['stage_label'],
                        'products' => $products,
                    ];
                }

                return ['intro' => 'INTRO-XYZ', 'steps' => $steps];
            }
        };
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

    /** Build a linked, firing retest through the normal pipeline; return the fresh retest. */
    private function firingRetest(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'spiro_retest_S99.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        // The normal create flow (CreateReport::afterCreate): link, recompute review
        // state (sets the deterministic retest_review flag), then run the one-pass build
        // (freeze comparison → summary → override → MATERIALISE forced plan).
        $retest->update(['retest_of_report_id' => $original->id]);
        ReportGeneration::recomputeReviewState($retest->fresh());
        ReportGeneration::buildRetestOnCreate($retest->fresh());
        $retest->update(['status' => 'published']);

        return $retest->fresh();
    }

    private function render(Report $report, string $view): string
    {
        return view($view, ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    public function test_forced_plan_is_materialised_on_the_model(): void
    {
        $retest = $this->firingRetest();
        $spiro = Plan::where('key', 'retest-spirochaetes')->firstOrFail();

        // Override fired → forced plan + reason, trigger products detached.
        $this->assertSame($spiro->id, $retest->plan_id);
        $this->assertSame('retest_spirochaetes', $retest->recommendation_reason['code']);
        $this->assertSame(0, $retest->catalogProducts()->count());

        // Plan MATERIALISED without a manual Apply click: the 3 Spirochaetes-plan steps
        // exist as report_steps with their products.
        $steps = $retest->steps()->with('products')->orderBy('position')->get();
        $this->assertCount(3, $steps);
        $productNames = $steps->flatMap(fn ($s) => $s->products->map(fn ($p) => $p->catalogProduct?->name))->filter()->values()->all();
        $this->assertContains('Antimicrobic', $productNames);
        $this->assertContains('PetBiome Prebiotic', $productNames);
        $this->assertContains('PetBiome Maintenance', $productNames);

        // Prices hidden on retests, and held for review.
        $this->assertTrue((bool) $retest->hide_commerce, 'retest should hide commerce/prices');
        $this->assertTrue((bool) $retest->needs_review);
        $codes = collect($retest->review_flags['issues'] ?? [])->pluck('code')->all();
        $this->assertContains('retest_review', $codes);
    }

    public function test_materialised_steps_carry_real_generated_copy_not_the_placeholder(): void
    {
        $retest = $this->firingRetest();

        // Every materialised product carries the GENERATED how_it_helps, not the
        // PlanInstantiation placeholder seed.
        $products = $retest->steps()->with('products')->get()->flatMap->products;
        $this->assertTrue($products->isNotEmpty());
        foreach ($products as $p) {
            $this->assertNotSame('[copy to be generated]', $p->how_it_helps);
            $this->assertStringStartsWith('HOW-', (string) $p->how_it_helps);
        }

        // The generated plan intro is persisted (not the '[intro to be generated]' seed).
        $this->assertSame('INTRO-XYZ', $retest->plan_intro);
    }

    public function test_plan_copy_runs_through_the_grounding_validator(): void
    {
        // A model that tries to hallucinate factual fields (product name + duration).
        $this->app->instance(OpenAiService::class, $this->fakeAi(drift: true));

        $retest = $this->firingRetest();
        $first = $retest->steps()->with('products.catalogProduct')->orderBy('position')->first();
        $product = $first->products->first();

        // validatePlanCopy locked the factual fields to the plan scaffold: the first
        // Spirochaetes step is Antimicrobic for 2 months — NOT the hallucinated values.
        $this->assertSame('Antimicrobic', $product->catalogProduct?->name);
        $this->assertNotSame('HALLUCINATED PRODUCT', $product->catalogProduct?->name);
        $this->assertSame('2 months', $product->duration);
        $this->assertNotSame('999 WRONG', $product->duration);

        // …while the prose copy slot (how_it_helps) still flowed through.
        $this->assertStringStartsWith('HOW-', (string) $product->how_it_helps);
    }

    public function test_web_render_shows_the_overridden_plan_steps_with_prices_hidden(): void
    {
        $html = $this->render($this->firingRetest(), 'report.show');

        // The plan section renders with the forced plan's products (steps visible).
        $this->assertStringContainsString('Antimicrobic', $html);
        $this->assertStringContainsString('PetBiome Prebiotic', $html);
        $this->assertStringContainsString('PetBiome Maintenance', $html);

        // Prices hidden on a retest: no subscribe CTA and no "/ month" price string.
        $this->assertStringNotContainsString('Subscribe to plan', $html);
        $this->assertStringNotContainsString('/ month', $html);
    }

    public function test_pdf_render_shows_the_overridden_plan_summary(): void
    {
        // The PDF renders a COMPACT plan summary (name + phase/step descriptor + the
        // Biome Balance box), not the full product cards — but its whole section is still
        // gated on plan_id && steps->isNotEmpty(), so it only appears because the forced
        // plan was materialised. Product cards live on the online report.
        $html = $this->render($this->firingRetest(), 'report.pdf');

        $this->assertStringContainsString('Recommended Next Steps', $html);         // plan section rendered
        $this->assertStringContainsString('Spirochaetes Reset (retest)', $html);    // the forced plan name
        $this->assertStringContainsString('guided step', $html);                    // descriptor from the 3 materialised steps
        $this->assertStringContainsString('Your Biome Balance Programme', $html);   // hide_commerce reassurance box
        // No commerce CTA / price on a retest.
        $this->assertStringNotContainsString('full plan &amp; subscription', $html);
        $this->assertStringNotContainsString('/ month', $html);
    }
}
