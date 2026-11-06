<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\AdminFormatting;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retest reports are auto-flagged for review (step 7f): the deterministic 'retest_review'
 * code fires on isRetest() through the existing review-flag system — on the link-save
 * (recomputeReviewState) and generation (regenerate) seams — so every retest lands in
 * review. "Mark as reviewed" clears it (publish still allowed); a regenerate re-raises it.
 */
class RetestReviewFlagTest extends TestCase
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

    /** Non-empty AI so regenerate reaches its success/persist path. */
    private function bindFakeAi(): void
    {
        $this->app->bind(OpenAiService::class, fn () => new class extends OpenAiService
        {
            public function generateReportInterpretations(array $p, float $d, array $c = [], array $x = [], ?int $r = null): array
            {
                return [
                    'summary' => 'S.', 'goal' => 'G.', 'bacteroidetes_interpretation' => 'B.',
                    'firmicutes_interpretation' => 'F.', 'fusobacteria_interpretation' => 'Fu.',
                    'proteobacteria_interpretation' => 'P.', 'diversity_interpretation' => 'D.',
                    'vet_summary' => 'V.', 'recommended_actions' => 'A.',
                ];
            }

            public function generateDietGuidance(array $p, float $d, array $c = [], array $x = [], ?int $r = null): array
            {
                return ['include' => 'Inc.', 'avoid' => 'Avo.'];
            }
        });
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

    /** An original + a linked retest (frozen). Returns [original, retest]. */
    private function seedLinkedRetest(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $originalTest = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $retestTest = $this->makeTest($pet, $client, 'teddy_retest_S12.csv', 'retest/r'.uniqid().'.csv');

        $original = ReportGeneration::createReportFromTest($originalTest->fresh())->fresh();
        $retest = ReportGeneration::createReportFromTest($retestTest->fresh())->fresh();

        $retest->update(['retest_of_report_id' => $original->id]);
        $retest->update(ReportGeneration::retestFreezePayload($retest->fresh()));

        return [$original->fresh(), $retest->fresh()];
    }

    private function codes(Report $report): array
    {
        return array_column($report->fresh()->review_flags['issues'] ?? [], 'code');
    }

    public function test_the_new_code_has_a_label_and_explanation(): void
    {
        $this->assertSame('Retest — review before publishing', AdminFormatting::reviewIssueLabel('retest_review'));
        $this->assertGreaterThan(40, strlen(AdminFormatting::reviewIssueExplanation('retest_review')));
    }

    public function test_link_save_flags_the_retest_via_recompute(): void
    {
        [, $retest] = $this->seedLinkedRetest();

        ReportGeneration::recomputeReviewState($retest->fresh());

        $this->assertTrue($retest->fresh()->needs_review);
        $this->assertContains('retest_review', $this->codes($retest));
    }

    public function test_regenerate_flags_the_retest(): void
    {
        $this->bindFakeAi();
        [, $retest] = $this->seedLinkedRetest();

        $result = ReportGeneration::regenerateReport($retest->fresh());
        $this->assertTrue($result['ok']);

        $this->assertTrue($retest->fresh()->needs_review);
        $this->assertContains('retest_review', $this->codes($retest));
    }

    public function test_marking_reviewed_clears_the_flag_and_it_can_still_publish(): void
    {
        [, $retest] = $this->seedLinkedRetest();
        ReportGeneration::recomputeReviewState($retest->fresh());
        $this->assertTrue($retest->fresh()->needs_review);

        // "Mark as reviewed" (the EditReport action): clears needs_review, keeps the issue.
        $retest->update(['needs_review' => false, 'reviewed_at' => now()]);
        $this->assertFalse($retest->fresh()->needs_review);
        $this->assertContains('retest_review', $this->codes($retest), 'issue kept for the audit trail');

        // A plain re-save (recompute) must NOT re-raise it — a prior "reviewed" holds.
        ReportGeneration::recomputeReviewState($retest->fresh());
        $this->assertFalse($retest->fresh()->needs_review, 'recompute respects the prior review');

        // Publishing is not blocked by the flag.
        $retest->fresh()->update(['status' => 'published']);
        $this->assertTrue($retest->fresh()->isPublished());
    }

    public function test_regenerate_reraises_after_a_review(): void
    {
        $this->bindFakeAi();
        [, $retest] = $this->seedLinkedRetest();
        ReportGeneration::recomputeReviewState($retest->fresh());

        $retest->update(['needs_review' => false, 'reviewed_at' => now()]);
        $this->assertFalse($retest->fresh()->needs_review);

        // A redo should be re-reviewed.
        ReportGeneration::regenerateReport($retest->fresh());
        $this->assertTrue($retest->fresh()->needs_review);
        $this->assertContains('retest_review', $this->codes($retest));
    }

    public function test_non_retest_report_is_not_flagged_with_the_retest_code(): void
    {
        $this->bindFakeAi();
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        ReportGeneration::recomputeReviewState($report->fresh());
        ReportGeneration::regenerateReport($report->fresh());

        $this->assertFalse($report->fresh()->isRetest());
        $this->assertNotContains('retest_review', $this->codes($report));
    }
}
