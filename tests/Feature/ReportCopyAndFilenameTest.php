<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Support\ReportContent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Three client-feedback copy/label changes:
 *   1. the Verrucomicrobia insight is titled just "Metabolic Health" (the "Gut
 *      Barrier" wording described the Blautia insight and was confusing);
 *   2. "Understanding Your Dog's Results" explains the three Microbiome Overview
 *      scores in the client's exact words, identically on web + PDF;
 *   3. the downloaded PDF is named "{Owner} - {Pet}.pdf", sanitised, with fallbacks.
 */
class ReportCopyAndFilenameTest extends TestCase
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
        config(['services.openai.api_key' => '']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function makeReport(string $clientName = 'Jane Smith', string $petName = 'Biscuit'): Report
    {
        $client = Client::create(['name' => $clientName, 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => $petName]);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'KMS734',
            'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Stable',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40], 'insight_taxa' => ['blautia' => 3.5]],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => $petName],
            'score_gut_barrier' => 'Healthy Optimal',
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report->fresh();
    }

    private function pdfHtml(Report $report): string
    {
        return view('report.pdf', ['report' => $report->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    // ── 1. Insight rename ────────────────────────────────────────────────────

    public function test_insight_is_titled_metabolic_health_on_web_and_pdf(): void
    {
        $report = $this->makeReport();

        foreach ([$this->get('/report/'.$report->public_token)->assertOk()->getContent(), $this->pdfHtml($report)] as $html) {
            $this->assertStringContainsString('Metabolic Health', $html);
            $this->assertStringNotContainsString('Gut Barrier &amp; Metabolic Health', $html);
            $this->assertStringNotContainsString('Gut Barrier & Metabolic Health', $html);
        }
    }

    public function test_the_field_key_is_unchanged_only_the_display_title_moved(): void
    {
        // Renaming is display-only: the config key (and therefore the DB column)
        // must still be score_gut_barrier.
        $this->assertArrayHasKey('score_gut_barrier', \App\Support\HealthInsightRules::HEALTH_INSIGHT_RULES);
        $this->assertSame('Metabolic Health', \App\Support\HealthInsightRules::HEALTH_INSIGHT_RULES['score_gut_barrier']['title']);
        $this->assertContains('score_gut_barrier', \App\Support\HealthInsightRules::scoreFields());
    }

    // ── 2. Understanding-your-results explanations ───────────────────────────

    public function test_the_three_explanations_render_verbatim_on_web_and_pdf(): void
    {
        $report = $this->makeReport();
        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        foreach (ReportContent::resultsExplanations() as $explanation) {
            foreach ([$web, $pdf] as $html) {
                $this->assertStringContainsString($explanation['title'], $html);
                // Blade escapes the copy; compare against the escaped form.
                $this->assertStringContainsString(e($explanation['text']), $html);
            }
        }
    }

    public function test_the_explanations_are_the_clients_exact_wording(): void
    {
        $byTitle = collect(ReportContent::resultsExplanations())->keyBy('title');

        $this->assertSame(
            'Diversity is measured using the Shannon Index, a standard method used to assess how varied and balanced the microbiome is. Higher scores indicate greater diversity and a more resilient microbiome.',
            $byTitle['Diversity']['text'],
        );
        $this->assertSame(
            'Species richness reflects the number of different bacterial species present. Higher numbers are typically associated with a more diverse and resilient microbiome.',
            $byTitle['Species Richness']['text'],
        );
        $this->assertSame(
            'The Dysbiosis Pattern Score reflects the balance between Firmicutes and Bacteroidetes.',
            $byTitle['Dysbiosis Pattern Score']['text'],
        );
    }

    // ── 3. PDF filename ──────────────────────────────────────────────────────

    public function test_pdf_download_is_named_owner_dash_pet(): void
    {
        $report = $this->makeReport('Jane Smith', 'Biscuit');

        $res = $this->get('/report/'.$report->public_token.'/pdf')->assertOk();

        $this->assertStringContainsString('application/pdf', strtolower($res->headers->get('content-type') ?? ''));
        $this->assertStringContainsString('Jane Smith - Biscuit.pdf', $res->headers->get('content-disposition') ?? '');
        // The old generic name is gone.
        $this->assertStringNotContainsString('report-biscuit', $res->headers->get('content-disposition') ?? '');
    }

    /** Build an unpersisted report to exercise the name handling directly. */
    private function named(?string $owner, ?string $pet, ?string $sample = 'KMS734'): Report
    {
        $report = new Report(['pet_snapshot' => $pet === null ? null : ['name' => $pet]]);
        $report->setRelation('pet', null);
        $report->setRelation('client', $owner === null ? null : new Client(['name' => $owner]));
        $report->setRelation('test', new Test(['sample_id' => $sample]));

        return $report;
    }

    public function test_filename_sanitises_and_falls_back_sensibly(): void
    {
        // Normal case.
        $this->assertSame('Jane Smith - Biscuit.pdf', ReportController::pdfFilename($this->named('Jane Smith', 'Biscuit')));

        // Accents transliterated to ASCII; apostrophes survive.
        $this->assertSame("Siobhan O'Brien - Cafe.pdf", ReportController::pdfFilename($this->named("Siobhán O'Brien", 'Café')));

        // Characters that are invalid in filenames are replaced, whitespace collapsed.
        $this->assertSame('Bad Name With Chars - Rex.pdf', ReportController::pdfFilename($this->named('Bad/Name\\With:Chars*?"<>|', 'Rex')));
        $this->assertSame('spaced out - Bo.pdf', ReportController::pdfFilename($this->named('   spaced   out   ', '  Bo  ')));

        // Missing either name drops that part cleanly.
        $this->assertSame('Biscuit.pdf', ReportController::pdfFilename($this->named(null, 'Biscuit')));
        $this->assertSame('Jane Smith.pdf', ReportController::pdfFilename($this->named('Jane Smith', null)));

        // Neither name usable → sample id, then a bare label. Never empty/broken.
        $this->assertSame('Report KMS734.pdf', ReportController::pdfFilename($this->named(null, null)));
        $this->assertSame('Report.pdf', ReportController::pdfFilename($this->named(null, null, null)));
        // Names that sanitise away to nothing behave like missing names.
        $this->assertSame('Report KMS734.pdf', ReportController::pdfFilename($this->named('...', '...')));
    }

    public function test_filename_truncates_unusually_long_names(): void
    {
        $name = ReportController::pdfFilename($this->named(str_repeat('VeryLongOwnerName', 8), str_repeat('LongPet', 12)));

        // Each part capped at 60 chars, so the whole name stays well under the ~255
        // filesystem limit while remaining readable.
        $this->assertLessThanOrEqual(130, strlen($name));
        $this->assertStringEndsWith('.pdf', $name);
        $this->assertStringStartsWith('VeryLongOwnerName', $name);
    }
}
