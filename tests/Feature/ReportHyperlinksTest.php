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
 * The report's contact email + web address (footer / contact bar / vet summary)
 * were plain text. They must now be real links: mailto: for the email, https:// for
 * the web address — on the WEB report and in the PDF (DomPDF supports <a href>).
 */
class ReportHyperlinksTest extends TestCase
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
    }

    private function makeReport(): Report
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $plan = Plan::create([
            'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/checkout/CLEAN',
            'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'diet' => 'Kibble']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-H', 'sample_id' => 'ORD-H',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan->id, 'status' => 'published',
            'pet_snapshot' => ['name' => 'Biscuit', 'diet' => 'Kibble'],
            'subscription_snapshot' => ['available' => true, 'price' => '£29.75 / month', 'url' => 'https://loop.test/checkout/CLEAN', 'includes' => []],
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    public function test_web_report_email_and_web_addresses_are_clickable(): void
    {
        $report = $this->makeReport();

        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // Email → mailto:, web address → https:// — with the displayed text unchanged.
        $this->assertStringContainsString('href="mailto:info@biome4pets.com"', $html);
        $this->assertStringContainsString('href="https://www.biome4pets.com"', $html);
        $this->assertStringContainsString('info@biome4pets.com</a>', $html);
        $this->assertStringContainsString('www.biome4pets.com</a>', $html);
    }

    public function test_pdf_report_email_and_web_addresses_are_clickable(): void
    {
        $report = $this->makeReport();

        $html = view('report.pdf', ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();

        // DomPDF renders <a href> as a clickable link; assert the markup is present.
        $this->assertStringContainsString('href="mailto:info@biome4pets.com"', $html);
        $this->assertStringContainsString('href="https://www.biome4pets.com"', $html);
    }

    public function test_pdf_endpoint_still_renders_with_the_new_links(): void
    {
        $report = $this->makeReport();

        // The real DomPDF render must not choke on the new <a> tags (styling intact).
        $res = $this->get('/report/'.$report->public_token.'/pdf')->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower($res->headers->get('content-type') ?? ''));
    }

    /**
     * The "View plan online" button stays a working clickable link to the report,
     * and the redundant raw URL text that used to print below it is gone. Both the
     * button and that removed link used the same pdf_view_online UTM, so it now
     * appears exactly ONCE (the button) instead of twice.
     */
    public function test_pdf_view_plan_button_links_to_report_and_raw_url_text_removed(): void
    {
        $report = $this->makeReport();

        $html = view('report.pdf', ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();

        // The button is present and links to the report (UTM-tagged).
        $this->assertStringContainsString('View plan online', $html);
        $this->assertStringContainsString('utm_content=pdf_view_online', $html);

        // Exactly one such link now — the redundant raw-URL link below it is removed.
        $this->assertSame(1, substr_count($html, 'utm_content=pdf_view_online'));
    }
}
