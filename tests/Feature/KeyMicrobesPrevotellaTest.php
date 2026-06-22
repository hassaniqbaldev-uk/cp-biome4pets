<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Support\ReportContent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #5 — the Prevotella key-microbe card has no real data (genus-level data isn't
 * retained), so it's a broken always-empty placeholder. keyMicrobes() drops any
 * card with no value AND no interpretation, so customers never see it — while the
 * four data-backed phyla still render.
 */
class KeyMicrobesPrevotellaTest extends TestCase
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
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-K', 'sample_id' => 'ORD-K',
            'report_date' => '2026-06-17',
            'phylum_data' => ['Fusobacteria' => 30, 'Bacteroidetes' => 25, 'Firmicutes' => 20, 'Proteobacteria' => 10],
            'diversity_score' => 2.4, 'species_richness' => 600, 'dysbiosis_score' => 0.45,
            'microbiome_classification' => 'Imbalanced', 'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create(['key' => 'p-'.uniqid(), 'name' => 'Restore & Rebalance', 'enabled' => true]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id, 'pet_snapshot' => ['name' => 'Biscuit'],
            'ai_bacteroidetes_interpretation' => 'B copy.', 'ai_firmicutes_interpretation' => 'F copy.',
            'ai_fusobacteria_interpretation' => 'Fu copy.', 'ai_proteobacteria_interpretation' => 'P copy.',
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_key_microbes_excludes_the_empty_prevotella_placeholder(): void
    {
        $report = $this->makeReport();

        $names = array_column(ReportContent::keyMicrobes($report), 'name');

        $this->assertNotContains('Prevotella', $names);                 // empty placeholder dropped
        $this->assertContains('Fusobacteria', $names);                  // data-backed cards kept
        $this->assertContains('Bacteroidetes', $names);
        $this->assertContains('Firmicutes', $names);
        $this->assertContains('Proteobacteria', $names);
        $this->assertCount(4, $names);
    }

    public function test_neither_view_renders_a_prevotella_card(): void
    {
        $report = $this->makeReport();

        $web = view('report.show', ['report' => $report])->render();
        $pdf = view('report.pdf', ['report' => $report])->render();

        // The Prevotella-specific copy (only present on that card) must be absent.
        $this->assertStringNotContainsString('Prevotella', $web);
        $this->assertStringNotContainsString('Prevotella', $pdf);
        // The kept cards still render.
        $this->assertStringContainsString('Fusobacteria', $web);
        $this->assertStringContainsString('Fusobacteria', $pdf);
    }
}
