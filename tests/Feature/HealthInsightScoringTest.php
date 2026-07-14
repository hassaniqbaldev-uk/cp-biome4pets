<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Support\HealthInsightRules;
use App\Support\ReportContent;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 2 wiring: report generation now sets the six health-insight score_* columns
 * DETERMINISTICALLY from the bacteria percentages (not the AI), the exact client
 * comments attach per band via ReportContent::healthInsights(), and an admin
 * override still takes effect (and re-points the comment).
 */
class HealthInsightScoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        // No API key: AI text comes back empty, but the scores are computed anyway.
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function testWith(array $phylumData, array $insightTaxa): Test
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'ORD-'.uniqid(),
            'report_date' => '2026-06-17',
            'phylum_data' => $phylumData,
            'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => $phylumData, 'insight_taxa' => $insightTaxa],
        ]);
    }

    public function test_generation_computes_the_six_scores_deterministically(): void
    {
        // Bacteroidetes 32 → High; Firmicutes 26 → High (behaviour) & High (stress);
        // Verrucomicrobia absent → Low; Blautia 3.5 → Target; E/S 0.1 → Low.
        $test = $this->testWith(
            phylumData: ['Bacteroidetes' => 32, 'Firmicutes' => 26],
            insightTaxa: ['blautia' => 3.5, 'escherichia_shigella' => 0.1],
        );

        $report = ReportGeneration::createReportFromTest($test);

        $this->assertSame('High', $report->score_skin_allergy);
        $this->assertSame('High', $report->score_behaviour_mood);
        $this->assertSame('Low', $report->score_gut_barrier);          // Verrucomicrobia absent → Low
        $this->assertSame('Optimal Health', $report->score_gut_wall);  // Blautia 3.5 → Optimal Health
        $this->assertSame('Low', $report->score_gas_digestive);        // E/S low = favourable
        $this->assertSame('High', $report->score_stress_resilience);
    }

    public function test_health_insights_attach_the_exact_client_comment_and_direction(): void
    {
        $test = $this->testWith(
            phylumData: ['Bacteroidetes' => 32, 'Firmicutes' => 26],
            insightTaxa: ['blautia' => 3.5, 'escherichia_shigella' => 0.1],
        );
        $report = ReportGeneration::createReportFromTest($test);

        $insights = ReportContent::healthInsights($report);

        // Skin & Allergy = High → exact client text, red/bad direction.
        $skin = $insights['score_skin_allergy'];
        $this->assertSame('High', $skin['label']);
        $this->assertStringStartsWith('Higher levels of Bacteroidetes', $skin['comment']);
        $this->assertSame('bad', $skin['tone']);
        $this->assertSame(32.0, $skin['value']);

        // Gas & Digestive = Low is FAVOURABLE (green) here.
        $gas = $insights['score_gas_digestive'];
        $this->assertSame('Low', $gas['label']);
        $this->assertTrue($gas['favourable']);
    }

    public function test_admin_override_takes_effect_and_repoints_the_comment(): void
    {
        $test = $this->testWith(
            phylumData: ['Bacteroidetes' => 32],
            insightTaxa: [],
        );
        $report = ReportGeneration::createReportFromTest($test);
        $this->assertSame('High', $report->score_skin_allergy);

        // Staff override the computed band to Low before publishing.
        $report->update(['score_skin_allergy' => 'Low']);

        $insights = ReportContent::healthInsights($report->fresh());
        $this->assertSame('Low', $insights['score_skin_allergy']['label']);
        $this->assertStringStartsWith('Low levels of Bacteroidetes', $insights['score_skin_allergy']['comment']);
    }

    public function test_all_override_options_are_valid_band_labels(): void
    {
        // The dropdown options equal that insight's own bands (so an override can
        // only ever be a valid band the validator accepts).
        $options = HealthInsightRules::labelOptions('score_gut_wall');
        $this->assertSame(['Optimal Health', 'Disrupted', 'Leaky Gut'], array_values($options));
    }
}
