<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PDF-only bug: the "Imbalanced" classification card rendered BIGGER than the other
 * two in every report, so it read as the selected one even when it wasn't.
 *
 * Cause: the card box was an inner <div> sized to its own content. "Imbalanced" has
 * the longest description, which wraps to two lines, so its box was ~9pt taller than
 * the other two (measured from the generated PDF: 46.73 / 55.88 / 46.73). The web is
 * unaffected — its CSS grid stretches all cards to equal height. Fix: put the card box
 * on the <td>, so the table row equalises all three cell heights (→ 52.14 / 52.14 /
 * 52.14), and emphasise ONLY the active card by colour (never by size).
 *
 * These tests lock: only the ACTUAL classification is emphasised for each of the three
 * values, the shared "Imbalanced" prefix never lights up two cards, all three titles
 * keep identical size/weight, and the real DomPDF endpoint still renders.
 */
class PdfClassificationEmphasisTest extends TestCase
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

    /** The 'active' background tint unique to each classification card. */
    private const TINT = [
        'Stable' => '#dcfce7',
        'Imbalanced' => '#fef3c7',
        'Imbalanced & Depleted' => '#fee2e2',
    ];

    private function reportWithClassification(string $classification): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(),
            'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => $classification,
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40]],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
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

    /**
     * For each of the three classifications: ONLY that card's tint appears; the other
     * two cards' tints are entirely absent (so they render un-emphasised).
     */
    #[DataProvider('classifications')]
    public function test_only_the_actual_classification_is_emphasised(string $classification): void
    {
        $html = $this->pdfHtml($this->reportWithClassification($classification));

        $this->assertStringContainsString(
            self::TINT[$classification],
            $html,
            "the actual classification '{$classification}' must be emphasised",
        );

        foreach (self::TINT as $name => $tint) {
            if ($name === $classification) {
                continue;
            }
            $this->assertStringNotContainsString(
                $tint,
                $html,
                "'{$name}' must NOT be emphasised when the dog is '{$classification}'",
            );
        }
    }

    public static function classifications(): array
    {
        return [
            'Stable' => ['Stable'],
            'Imbalanced' => ['Imbalanced'],
            'Imbalanced & Depleted' => ['Imbalanced & Depleted'],
        ];
    }

    /**
     * The shared-prefix guard: "Imbalanced" is a prefix of "Imbalanced & Depleted", so
     * a substring match would emphasise BOTH. Matching must be exact.
     */
    public function test_imbalanced_and_depleted_does_not_also_emphasise_imbalanced(): void
    {
        $html = $this->pdfHtml($this->reportWithClassification('Imbalanced & Depleted'));

        $this->assertStringContainsString('#fee2e2', $html);                 // the real one
        $this->assertStringNotContainsString('#fef3c7', $html, 'plain "Imbalanced" must stay un-emphasised');
        // Both card titles still print — only the emphasis differs.
        $this->assertStringContainsString('Imbalanced &amp; Depleted', $html);
    }

    /** Emphasis is by COLOUR only — never size. All three card titles keep identical
     *  font-size/weight, so no card can render "bigger" than another. */
    public function test_all_three_card_titles_have_identical_size_and_weight(): void
    {
        $html = $this->pdfHtml($this->reportWithClassification('Stable'));

        // Each card's own title must carry the SAME 14px/bold declaration — only the
        // colour differs between active and inactive.
        foreach (['Stable', 'Imbalanced', 'Imbalanced &amp; Depleted'] as $name) {
            $this->assertMatchesRegularExpression(
                '/font-size: 14px; font-weight: bold; color: #[0-9a-fA-F]{6};">'.preg_quote($name, '/').'<\/div>/',
                $html,
                "card '{$name}' must use the shared 14px bold title style",
            );
        }
    }

    /** Regression guard for the height bug: the card box must sit on the <td> (so the
     *  table row equalises heights), never on an inner auto-height div. */
    public function test_card_box_is_on_the_table_cell_so_heights_are_equal(): void
    {
        $html = $this->pdfHtml($this->reportWithClassification('Imbalanced'));

        // The td carries the card's background + border-top (the box).
        $this->assertMatchesRegularExpression(
            '/<td style="width: 32%; vertical-align: top; background-color: #[0-9a-fA-F]{6}; border-top: 4px solid/',
            $html,
        );
        // …and the old inner styled div is gone.
        $this->assertStringNotContainsString(
            '<div style="background-color: #fef3c7; border-top: 4px solid',
            $html,
        );
    }

    /** The real DomPDF render must still succeed for every classification. */
    public function test_dompdf_endpoint_still_renders_a_pdf(): void
    {
        foreach (array_keys(self::TINT) as $classification) {
            $report = $this->reportWithClassification($classification);
            $res = $this->get('/report/'.$report->public_token.'/pdf')->assertOk();
            $this->assertStringContainsString(
                'application/pdf',
                strtolower($res->headers->get('content-type') ?? ''),
                "PDF must render for '{$classification}'",
            );
        }
    }
}
