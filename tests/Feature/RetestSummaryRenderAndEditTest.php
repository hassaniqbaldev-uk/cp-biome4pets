<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Services\CsvParserService;
use App\Support\ReportGeneration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI retest summary rendered (web + PDF) and editable (admin). The conclusion headlines
 * the retest section; the five parts render after the data blocks; an empty summary shows
 * nothing. Six Textareas (dot-path to retest_summary.*) edit it, gated to a retest and
 * prefilled from the stored value. Uses the real Teddy fixtures.
 */
class RetestSummaryRenderAndEditTest extends TestCase
{
    private const FULL = [
        'conclusion' => 'OVERALL-CONCLUSION-XYZ',
        'started' => 'STARTED-XYZ',
        'improved' => 'IMPROVED-XYZ',
        'attention' => 'ATTENTION-XYZ',
        'meaning' => 'MEANING-XYZ',
        'recommend' => 'RECOMMEND-XYZ',
    ];

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

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
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

    /** A published, linked retest. Returns the retest report. */
    private function seedLinkedRetest(?array $summary = null): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));
        $retest->update(['status' => 'published']);
        if ($summary !== null) {
            $retest->update(['retest_summary' => $summary]);
        }

        return $retest->fresh();
    }

    private function web(Report $report): string
    {
        return view('report.show', ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    private function pdf(Report $report): string
    {
        return view('report.pdf', ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    private function assertRendersSummary(string $html): void
    {
        $this->assertStringContainsString('OVERALL-CONCLUSION-XYZ', $html);
        $this->assertStringContainsString('Retest summary', $html);
        foreach (['Where we started', 'What has improved', 'What still needs attention', 'What this means', 'What we recommend next'] as $label) {
            $this->assertStringContainsString($label, $html, "label {$label} missing");
        }
        foreach (['STARTED-XYZ', 'IMPROVED-XYZ', 'ATTENTION-XYZ', 'MEANING-XYZ', 'RECOMMEND-XYZ'] as $text) {
            $this->assertStringContainsString($text, $html, "text {$text} missing");
        }
    }

    public function test_web_renders_conclusion_and_the_five_parts(): void
    {
        $this->assertRendersSummary($this->web($this->seedLinkedRetest(self::FULL)));
    }

    public function test_pdf_renders_conclusion_and_the_five_parts(): void
    {
        $this->assertRendersSummary($this->pdf($this->seedLinkedRetest(self::FULL)));
    }

    public function test_empty_summary_renders_no_block_or_conclusion(): void
    {
        $retest = $this->seedLinkedRetest(null); // retest_summary stays null

        $web = $this->web($retest);
        $pdf = $this->pdf($retest);

        // The retest section itself still renders, but no summary block/conclusion.
        $this->assertStringContainsString('Retest Progress', $web);
        $this->assertStringNotContainsString('Retest summary', $web);
        $this->assertStringNotContainsString('OVERALL-CONCLUSION-XYZ', $web);
        $this->assertStringNotContainsString('Retest summary', $pdf);
    }

    public function test_admin_fields_are_visible_and_prefilled_for_a_retest(): void
    {
        $retest = $this->seedLinkedRetest(self::FULL);

        $component = Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->assertFormFieldIsVisible('retest_summary.conclusion')
            ->assertFormFieldIsVisible('retest_summary.recommend');

        $this->assertSame('OVERALL-CONCLUSION-XYZ', $component->get('data.retest_summary.conclusion'));
        $this->assertSame('RECOMMEND-XYZ', $component->get('data.retest_summary.recommend'));
    }

    public function test_editing_a_field_persists_and_the_report_renders_the_edit(): void
    {
        $retest = $this->seedLinkedRetest(self::FULL);

        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->set('data.retest_summary.conclusion', 'EDITED-HEADLINE-123')
            ->set('data.retest_summary.recommend', 'EDITED-RECOMMEND-123')
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $retest->fresh()->retest_summary;
        $this->assertSame('EDITED-HEADLINE-123', $stored['conclusion']);
        $this->assertSame('EDITED-RECOMMEND-123', $stored['recommend']);

        $html = $this->get(route('report.show', $retest->fresh()->public_token))->assertOk()->getContent();
        $this->assertStringContainsString('EDITED-HEADLINE-123', $html);
        $this->assertStringContainsString('EDITED-RECOMMEND-123', $html);
        $this->assertStringNotContainsString('OVERALL-CONCLUSION-XYZ', $html);
    }

    public function test_fields_are_hidden_for_a_non_retest_report(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertFormFieldIsHidden('retest_summary.conclusion')
            ->assertFormFieldIsHidden('retest_summary.recommend')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($report->fresh()->retest_summary);
    }
}
