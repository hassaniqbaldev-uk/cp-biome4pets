<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The subscription price box conveys the saving: the full price struck through
 * next to the discounted price, with a "15% off" badge (web), and an equivalent
 * "15% off the usual £35" clause in the PDF. Frozen from the subscription
 * snapshot. (The £29.75 / £35 numbers are display strings — not asserted as
 * computed; we only check the presentation + the corrected 15% label.)
 */
class SubscriptionPriceDisplayTest extends TestCase
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

    private function makeReport(array $snapshotOverrides): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o' . uniqid() . '@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-P', 'sample_id' => 'ORD-P',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create(['key' => 'p-' . uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true]);

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id,
            'pet_snapshot' => ['name' => 'Biscuit'],
            'subscription_snapshot' => array_merge([
                'available' => true,
                'price' => '£29.75 / month',
                'full_price' => '£35 / month',
                'saving_label' => '15% off',
                'billing_note' => 'Save 15% vs buying separately · billed monthly',
                'url' => 'https://biome4pets.com/subscribe',
                'includes' => [],
            ], $snapshotOverrides),
        ]);

        ReportStep::create([
            'report_id' => $report->id, 'title' => 'Step 1', 'type' => 'prose',
            'stage_label' => 'Phase 1', 'body' => 'Guidance.', 'position' => 0,
        ]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'steps.products.catalogProduct']);
    }

    public function test_web_box_shows_struck_full_price_discounted_price_and_15pct_badge(): void
    {
        $web = view('report.show', ['report' => $this->makeReport([])])->render();

        $this->assertStringContainsString('£35 / month', $web);     // full price
        $this->assertStringContainsString('line-through', $web);    // struck through
        $this->assertStringContainsString('£29.75 / month', $web);  // discounted price
        $this->assertStringContainsString('15% off', $web);         // badge / label
        // The old wrong discount label is gone (ignore incidental numeric values).
        $this->assertStringNotContainsStringIgnoringCase('save 20%', $web);
        $this->assertStringNotContainsStringIgnoringCase('20% off', $web);
    }

    public function test_pdf_clause_conveys_15pct_off_the_full_price(): void
    {
        $report = $this->makeReport([]);

        $pdfHtml = view('report.pdf', ['report' => $report])->render();
        $this->assertStringContainsString('£29.75 / month', $pdfHtml);
        $this->assertStringContainsString('15% off the usual £35 / month', $pdfHtml);
        $this->assertStringNotContainsStringIgnoringCase('save 20%', $pdfHtml);
        $this->assertStringNotContainsStringIgnoringCase('20% off', $pdfHtml);

        // DomPDF renders without error (line-through / clause are PDF-safe).
        $pdf = Pdf::loadView('report.pdf', ['report' => $report])->setPaper('a4', 'portrait')->output();
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }

    public function test_no_struck_price_when_full_price_absent(): void
    {
        // e.g. the £132 intro plan — no full_price/saving, so no old→new shown.
        $report = $this->makeReport(['price' => '£132 / month', 'full_price' => null, 'saving_label' => null]);

        $web = view('report.show', ['report' => $report])->render();
        $this->assertStringContainsString('£132 / month', $web);
        $this->assertStringNotContainsString('line-through', $web);

        $pdfHtml = view('report.pdf', ['report' => $report])->render();
        $this->assertStringContainsString('subscription from £132 / month', $pdfHtml);
        $this->assertStringNotContainsString('the usual', $pdfHtml);
    }
}
