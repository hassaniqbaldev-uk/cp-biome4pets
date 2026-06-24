<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * hide_subscribe — a manual staff toggle that hides the commercial "Recommended
 * Next Steps" / subscribe pitch on a report (retests, already-on-programme), while
 * the clinical FINDINGS always stay. Default false = unchanged behaviour. Applies
 * to web + PDF, and the subscribe interstitial redirects back to the report.
 */
class HideSubscribeTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function makeReport(bool $hideSubscribe = false): Report
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR '.uniqid(), 'price' => 35, 'is_active' => true]);
        $plan = Plan::create([
            'key' => 'restore-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/x',
            'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-H', 'sample_id' => 'ORD-H',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id, 'hide_subscribe' => $hideSubscribe,
            'pet_snapshot' => ['name' => 'Biscuit'],
            'vet_summary' => 'A clear veterinary summary of the findings.',
            'subscription_snapshot' => ['available' => true, 'price' => '£29.75 / month', 'url' => 'https://loop.test/x', 'includes' => [['name' => 'PetBiome AMR', 'price' => 35]]],
        ]);
        // A product step so the plan/subscribe section would render when not hidden.
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step 1: Reset', 'type' => 'product', 'stage_label' => 'Phase 1 · Months 1-3', 'position' => 0])
            ->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_field_defaults_false_and_casts_boolean(): void
    {
        $report = $this->makeReport();
        $this->assertFalse($report->hide_subscribe);
        $this->assertIsBool($report->hide_subscribe);
    }

    public function test_default_false_shows_the_subscribe_pitch_as_before(): void
    {
        $report = $this->makeReport(hideSubscribe: false);

        $web = view('report.show', ['report' => $report])->render();
        $this->assertStringContainsString('Recommended Next Steps', $web);
        $this->assertStringContainsString('Subscribe to the', $web);   // subscribe box
        $this->assertStringContainsString('£29.75 / month', $web);     // price pitch
    }

    public function test_true_hides_the_subscribe_pitch_in_web_but_keeps_findings(): void
    {
        $report = $this->makeReport(hideSubscribe: true);

        $web = view('report.show', ['report' => $report])->render();

        // Commercial pitch is gone (the section heading + its distinctive content;
        // a CSS comment mentions the phrase, so assert the rendered <h2> heading).
        $this->assertStringNotContainsString('>Recommended Next Steps</h2>', $web);
        $this->assertStringNotContainsString('Subscribe to the', $web);
        $this->assertStringNotContainsString('Ready to get started?', $web);
        $this->assertStringNotContainsString('>Your plan at a glance</h3>', $web);

        // Findings ALWAYS remain.
        $this->assertStringContainsString('Microbiome Overview', $web);          // metrics
        $this->assertStringContainsString('Microbiome Classification', $web);    // classification
        $this->assertStringContainsString('Microbiome-Driven Health Insights', $web); // insights
        $this->assertStringContainsString('A clear veterinary summary', $web);   // vet summary
        $this->assertStringContainsString('Your Dog vs Healthy Microbiome', $web); // charts
    }

    public function test_true_hides_the_plan_pitch_in_pdf_but_keeps_findings(): void
    {
        $report = $this->makeReport(hideSubscribe: true);

        $pdf = view('report.pdf', ['report' => $report])->render();

        $this->assertStringNotContainsString('Recommended Next Steps', $pdf);    // plan/subscribe section gone
        $this->assertStringNotContainsString('available as a subscription', $pdf);

        // Findings remain in the PDF too.
        $this->assertStringContainsString('Microbiome Classification', $pdf);
        $this->assertStringContainsString('A clear veterinary summary', $pdf);

        // And the default (not hidden) PDF still shows the plan section.
        $shown = view('report.pdf', ['report' => $this->makeReport(hideSubscribe: false)])->render();
        $this->assertStringContainsString('Recommended Next Steps', $shown);
    }

    public function test_subscribe_interstitial_redirects_back_when_hidden(): void
    {
        $report = $this->makeReport(hideSubscribe: true);

        // No dead-end / no driving to checkout — the /subscribe route redirects to
        // the report when the pitch is hidden.
        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertRedirect('/report/'.$report->public_token);

        // Not hidden → the interstitial still serves as normal.
        $shown = $this->makeReport(hideSubscribe: false);
        $this->get('/report/'.$shown->public_token.'/subscribe')->assertOk();
    }
}
