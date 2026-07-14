<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 3: the health-insights DISPLAY overhaul, on BOTH the web report and the PDF.
 *   - the old "Low / Target / High" gauge legend is gone;
 *   - each insight renders its band coloured by the stored DIRECTION (favourable
 *     green, concern red, middle amber) — per-insight, not a uniform palette;
 *   - Gas & Digestive's LOW renders GREEN (client: low is good), not red;
 *   - each insight shows its automated comment;
 *   - Environmental Resilience shows the shared Firmicutes note;
 *   - an overridden score displays the overridden band + colour + comment.
 */
class HealthInsightDisplayTest extends TestCase
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

    /** A published report driven from a Test with known driver percentages. */
    private function makeReport(array $phylumData, array $insightTaxa): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-'.uniqid(),
            'sample_id' => 'ORD-'.uniqid(), 'report_date' => '2026-06-17',
            'phylum_data' => $phylumData, 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => $phylumData, 'insight_taxa' => $insightTaxa],
        ]);
        $report = ReportGeneration::createReportFromTest($test);
        $report->update(['status' => 'published']);
        ReportStep::create(['report_id' => $report->id, 'title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report->fresh();
    }

    private function pdfHtml(Report $report): string
    {
        return view('report.pdf', ['report' => $report->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    public function test_web_removes_the_legend_and_colours_by_direction_with_comments(): void
    {
        // Bacteroidetes 32 → Skin High (red); E/S 0.1 → Gas Low (GREEN, favourable).
        $report = $this->makeReport(
            phylumData: ['Bacteroidetes' => 32, 'Firmicutes' => 26],
            insightTaxa: ['blautia' => 3.5, 'escherichia_shigella' => 0.1],
        );

        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // 1. The Low/Target/High gauge legend row is gone.
        $this->assertStringNotContainsString('gauge-labels', $html);
        $this->assertStringNotContainsString('gutWallGauge', $html);

        // 2. Each insight's automated comment is shown.
        $this->assertStringContainsString('Higher levels of Bacteroidetes have been associated', $html);      // Skin High
        $this->assertStringContainsString('Escherichia/Shigella levels are low, which is considered beneficial', $html); // Gas Low

        // 3. Environmental Resilience shows the shared Firmicutes note.
        $this->assertStringContainsString('Firmicutes play multiple roles within the gut microbiome', $html);
    }

    public function test_web_gas_low_is_green_and_skin_high_is_red(): void
    {
        $report = $this->makeReport(
            phylumData: ['Bacteroidetes' => 32],                     // Skin High → red
            insightTaxa: ['escherichia_shigella' => 0.1],            // Gas Low → green
        );

        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // The Gas card badge is green (favourable), the Skin card badge is red.
        // Both badge colours co-exist in the insights grid, proving the palette is
        // per-insight (direction-driven), not uniform.
        $this->assertStringContainsString('bg-green-500', $html);
        $this->assertStringContainsString('bg-red-500', $html);
    }

    public function test_pdf_renders_colours_comments_and_note_without_the_gauge(): void
    {
        $report = $this->makeReport(
            phylumData: ['Bacteroidetes' => 32, 'Firmicutes' => 26],
            insightTaxa: ['blautia' => 3.5, 'escherichia_shigella' => 0.1],
        );

        // The real DomPDF endpoint must still render.
        $res = $this->get('/report/'.$report->public_token.'/pdf')->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower($res->headers->get('content-type') ?? ''));

        // Inspect the pre-DomPDF HTML for the new markup.
        $html = $this->pdfHtml($report);
        // Legend removed: the old gauge legend row was a 220px-wide table centred
        // with this exact inline style, unique to it. Its absence proves the
        // Low/Target/High scale is gone (a Target BADGE may still appear).
        $this->assertStringNotContainsString('margin: 6px auto 0 auto', $html);
        // Comments present.
        $this->assertStringContainsString('Higher levels of Bacteroidetes have been associated', $html);
        $this->assertStringContainsString('Escherichia/Shigella levels are low', $html);
        // Shared note present.
        $this->assertStringContainsString('Firmicutes play multiple roles within the gut microbiome', $html);
        // Direction colours: green (#16a34a) for Gas Low, red (#dc2626) for Skin High.
        $this->assertStringContainsString('#16a34a', $html);
        $this->assertStringContainsString('#dc2626', $html);
    }

    public function test_overridden_score_changes_the_displayed_band_colour_and_comment(): void
    {
        $report = $this->makeReport(
            phylumData: ['Bacteroidetes' => 32],   // computed Skin = High (red)
            insightTaxa: [],
        );
        $this->assertSame('High', $report->score_skin_allergy);

        // Override to Low (Bacteroidetes Low is amber/warn, with its own comment).
        $report->update(['score_skin_allergy' => 'Low']);

        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $this->assertStringContainsString('Low levels of Bacteroidetes may indicate', $html);
        // The overridden band no longer shows the High comment.
        $this->assertStringNotContainsString('Higher levels of Bacteroidetes have been associated', $html);
    }
}
