<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Setting;
use App\Models\Test;
use App\Support\HealthInsightRules;
use App\Support\ReportContent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The six health-insight descriptions are admin-editable in Settings, seeded with the
 * client's scientific wording, and fall back to the config default when blank so a
 * card can never render an empty description. Web + PDF both read them via
 * ReportContent::healthInsights().
 */
class HealthInsightDescriptionSettingsTest extends TestCase
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

    private function makeReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(), 'report_date' => '2026-06-17',
            'phylum_data' => ['Firmicutes' => 40, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'species_richness' => 500, 'dysbiosis_score' => 0.4,
            'microbiome_classification' => 'Stable',
            'csv_data' => ['phylum_totals' => ['Firmicutes' => 40], 'insight_taxa' => ['blautia' => 3.5]],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
            'score_gut_wall' => 'Optimal Health', 'score_gut_barrier' => 'Healthy Optimal',
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

    // ── Seeded values + the confusing field mapping ──────────────────────────

    /**
     * THE TRAP: the client's "Gut Barrier" comment is about BLAUTIA, so it belongs to
     * score_gut_wall (Gut Wall Integrity) — NOT score_gut_barrier, which is Metabolic
     * Health, driven by Verrucomicrobia. Each description must name its own driver.
     */
    public function test_the_blautia_gut_barrier_text_is_mapped_to_gut_wall_not_gut_barrier(): void
    {
        $gutWall = HealthInsightRules::HEALTH_INSIGHT_RULES['score_gut_wall'];
        $gutBarrier = HealthInsightRules::HEALTH_INSIGHT_RULES['score_gut_barrier'];

        // score_gut_wall = Blautia, and carries the Blautia/"gut barrier" copy.
        $this->assertSame('Blautia', $gutWall['driver']);
        $this->assertStringStartsWith('Evaluates the abundance of Blautia', $gutWall['desc']);
        $this->assertStringContainsString('intestinal barrier integrity', $gutWall['desc']);

        // score_gut_barrier = Verrucomicrobia (Metabolic Health) and must NOT have it.
        $this->assertSame('Verrucomicrobia', $gutBarrier['driver']);
        $this->assertSame('Metabolic Health', $gutBarrier['title']);
        $this->assertStringNotContainsString('Blautia', $gutBarrier['desc']);
    }

    public function test_each_seeded_description_matches_the_clients_exact_text(): void
    {
        $expected = [
            'score_skin_allergy' => 'This score evaluates microbiome characteristics associated with immune regulation, production of beneficial microbial metabolites and maintenance of the intestinal barrier, all of which influence systemic inflammation and allergic susceptibility. The assessment identifies microbial patterns that may increase or reduce the risk of microbiome-associated skin and immune dysfunction.',
            'score_gut_wall' => 'Evaluates the abundance of Blautia, a beneficial bacterial group associated with maintaining intestinal barrier integrity and supporting anti-inflammatory activity within the gut. Reduced levels may be associated with impaired gut barrier function, increased intestinal permeability and greater exposure of the immune system to secondary metabolites and toxins.',
            'score_gas_digestive' => 'Evaluates the abundance of Escherichia/Shigella, bacterial groups that can increase during gut microbial imbalance and are associated with intestinal inflammation and digestive disturbance. Elevated levels may indicate reduced microbial stability and a greater likelihood of gastrointestinal discomfort, altered stool quality and impaired digestive health.',
            'score_stress_resilience' => 'Assesses Firmicutes, a dominant bacterial phylum associated with microbial resilience, metabolic flexibility and maintenance of a stable gut ecosystem. Adequate abundance supports resistance to environmental challenges, helping the microbiome recover from dietary changes, stress and other factors that may disrupt microbial balance.',
            'score_behaviour_mood' => 'Evaluates the abundance of Firmicutes, a major bacterial phylum that includes many beneficial species involved in the gut-brain axis. These bacteria support the production of short-chain fatty acids and stimulate pathways involved in serotonin synthesis, helping to regulate mood, behaviour and stress resilience through microbiome-brain communication.',
            // No new wording supplied → keeps the ORIGINAL copy (never blank).
            'score_gut_barrier' => 'Reflects the functional capacity of the gut barrier and efficiency of nutrient metabolism.',
        ];

        foreach ($expected as $field => $text) {
            $this->assertSame($text, HealthInsightRules::HEALTH_INSIGHT_RULES[$field]['desc'], "desc mismatch for {$field}");
        }
    }

    /** Every insight gets its own distinct settings key. */
    public function test_every_insight_has_a_distinct_description_setting_key(): void
    {
        $keys = array_map(
            fn (string $f): string => HealthInsightRules::descriptionSettingKey($f),
            HealthInsightRules::scoreFields(),
        );

        $this->assertCount(6, $keys);
        $this->assertSame($keys, array_unique($keys));
        $this->assertSame('health_insight_desc_score_gut_wall', HealthInsightRules::descriptionSettingKey('score_gut_wall'));
    }

    // ── Settings override + fallback ─────────────────────────────────────────

    public function test_settings_value_overrides_the_config_default(): void
    {
        Setting::set(HealthInsightRules::descriptionSettingKey('score_gut_wall'), 'Client edited wording for gut wall.');

        $this->assertSame('Client edited wording for gut wall.', ReportContent::insightDescription('score_gut_wall'));
    }

    public function test_unset_or_blank_setting_falls_back_to_the_config_default(): void
    {
        // Never set at all.
        $this->assertSame(
            HealthInsightRules::HEALTH_INSIGHT_RULES['score_gas_digestive']['desc'],
            ReportContent::insightDescription('score_gas_digestive'),
        );

        // Explicitly blanked (the client wipes the field to restore the default).
        Setting::set(HealthInsightRules::descriptionSettingKey('score_gas_digestive'), '');
        $this->assertSame(
            HealthInsightRules::HEALTH_INSIGHT_RULES['score_gas_digestive']['desc'],
            ReportContent::insightDescription('score_gas_digestive'),
        );
        $this->assertNotSame('', ReportContent::insightDescription('score_gas_digestive'));

        // Whitespace-only is treated as blank too.
        Setting::set(HealthInsightRules::descriptionSettingKey('score_gas_digestive'), "   \n  ");
        $this->assertSame(
            HealthInsightRules::HEALTH_INSIGHT_RULES['score_gas_digestive']['desc'],
            ReportContent::insightDescription('score_gas_digestive'),
        );
    }

    public function test_no_insight_ever_renders_a_blank_description(): void
    {
        $report = $this->makeReport();

        foreach (ReportContent::healthInsights($report) as $field => $insight) {
            $this->assertNotSame('', trim($insight['desc']), "{$field} rendered a blank description");
        }
    }

    // ── Web + PDF both read the Settings value ───────────────────────────────

    public function test_web_and_pdf_both_render_the_settings_value(): void
    {
        Setting::set(HealthInsightRules::descriptionSettingKey('score_gut_wall'), 'EDITED-BY-CLIENT gut wall description.');
        $report = $this->makeReport();

        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        foreach (['web' => $web, 'pdf' => $pdf] as $where => $html) {
            $this->assertStringContainsString('EDITED-BY-CLIENT gut wall description.', $html, "{$where} must show the edited copy");
            // …and the superseded default is gone.
            $this->assertStringNotContainsString('Evaluates the abundance of Blautia, a beneficial bacterial group', $html);
        }
    }

    public function test_web_and_pdf_show_the_seeded_defaults_when_nothing_is_set(): void
    {
        $report = $this->makeReport();

        $web = $this->get('/report/'.$report->public_token)->assertOk()->getContent();
        $pdf = $this->pdfHtml($report);

        foreach ([$web, $pdf] as $html) {
            $this->assertStringContainsString(e('Evaluates the abundance of Blautia'), $html);
            $this->assertStringContainsString(e('Evaluates the abundance of Escherichia/Shigella'), $html);
        }
    }
}
