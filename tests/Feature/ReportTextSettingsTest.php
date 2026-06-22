<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Setting;
use App\Models\Test;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The static every-report text blocks (About / Our Approach / Support) are now
 * admin-editable Settings, read by BOTH the web report and the PDF via
 * ReportContent — so one edit updates both and they can never drift. Blank
 * reverts to the seeded default. Admin text is escaped (never raw HTML).
 */
class ReportTextSettingsTest extends TestCase
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

    private function makeReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-T', 'sample_id' => 'ORD-T',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create(['key' => 'p-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id, 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step 1', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    /** @return array{0:string,1:string} [web html, pdf html] */
    private function renderBoth(): array
    {
        $report = $this->makeReport();

        return [
            view('report.show', ['report' => $report])->render(),
            view('report.pdf', ['report' => $report])->render(),
        ];
    }

    public function test_defaults_render_identically_in_both_views(): void
    {
        // No settings set → both views fall back to the seeded defaults.
        foreach ($this->renderBoth() as $html) {
            $this->assertStringContainsString('16S rRNA', $html);                          // About / method
            $this->assertStringContainsString('not intended to diagnose disease', $html);  // disclaimer
            $this->assertStringContainsString('Large-scale canine microbiome database', $html); // Our Approach bullet
            $this->assertStringContainsString('AI-driven analysis and pattern recognition', $html);
            $this->assertStringContainsString('info@biome4pets.com', $html);               // Support
        }
    }

    public function test_seeder_populates_the_three_blocks(): void
    {
        (new SettingsSeeder())->run();

        $this->assertSame(Setting::REPORT_ABOUT_TEXT_DEFAULT, Setting::get(Setting::REPORT_ABOUT_TEXT));
        $this->assertSame(Setting::REPORT_APPROACH_TEXT_DEFAULT, Setting::get(Setting::REPORT_APPROACH_TEXT));
        $this->assertSame(Setting::REPORT_SUPPORT_TEXT_DEFAULT, Setting::get(Setting::REPORT_SUPPORT_TEXT));
    }

    public function test_editing_a_block_updates_both_web_and_pdf(): void
    {
        Setting::set(Setting::REPORT_ABOUT_TEXT, 'CUSTOM about blurb for this clinic.');
        Setting::set(Setting::REPORT_APPROACH_TEXT, "First custom point\nSecond custom point");
        Setting::set(Setting::REPORT_SUPPORT_TEXT, 'Call our clinic any weekday.');

        foreach ($this->renderBoth() as $html) {
            $this->assertStringContainsString('CUSTOM about blurb for this clinic.', $html);
            $this->assertStringContainsString('First custom point', $html);
            $this->assertStringContainsString('Second custom point', $html);
            $this->assertStringContainsString('Call our clinic any weekday.', $html);
            // The original defaults are gone — the edit drives the output.
            $this->assertStringNotContainsString('Large-scale canine microbiome database', $html);
            $this->assertStringNotContainsString('16S rRNA', $html);
        }
    }

    public function test_blank_setting_falls_back_to_default(): void
    {
        Setting::set(Setting::REPORT_ABOUT_TEXT, '');   // explicitly blanked

        [$web, $pdf] = $this->renderBoth();

        $this->assertStringContainsString('16S rRNA', $web);   // default restored
        $this->assertStringContainsString('16S rRNA', $pdf);
    }

    public function test_admin_text_is_escaped_not_raw_html(): void
    {
        Setting::set(Setting::REPORT_SUPPORT_TEXT, 'Hi <script>alert(1)</script> there');

        foreach ($this->renderBoth() as $html) {
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);   // escaped, XSS-safe
        }
    }
}
