<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource;
use App\Filament\Resources\PetResource\Widgets\PetTimelineWidget;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Support\PetTimeline;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 4: the per-pet timeline merges Tests, Reports and Health notes into one
 * newest-first feed, with type + date-range filters, linking out to the report
 * editor / public report / the pet's Tests section.
 */
class PetTimelineTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        // getUrl() (report editor / pet hub links) needs a current panel + auth.
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Pet with: note 2026-01-15, test 2026-03-01, report 2026-03-01, note 2026-05-01.
     */
    private function petWithEvents(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $test = Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'ORD-1',
            'sample_id' => 'ORD-1',
            'report_date' => '2026-03-01',
            'status' => 'results_received',
            'microbiome_classification' => 'Imbalanced',
            'diversity_score' => 3.0,
        ]);

        $report = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'test_id' => $test->id,
            'status' => 'published',
        ]);

        $pet->healthNotes()->create(['date' => '2026-01-15', 'weight_kg' => 7.2, 'note' => 'Started kibble']);
        $pet->healthNotes()->create(['date' => '2026-05-01', 'weight_kg' => 7.6]);

        return [$pet, $test, $report];
    }

    public function test_merged_feed_includes_all_three_types_newest_first(): void
    {
        [$pet] = $this->petWithEvents();

        $events = PetTimeline::build($pet->fresh());

        $this->assertCount(4, $events);

        // Newest-first: note(05-01), then test & report (03-01), then note(01-15).
        $this->assertSame('note', $events[0]['type']);
        $this->assertSame('2026-05-01', $events[0]['date']->toDateString());
        $this->assertSame('note', $events->last()['type']);
        $this->assertSame('2026-01-15', $events->last()['date']->toDateString());

        // All three types present.
        $this->assertEqualsCanonicalizing(
            ['note', 'test', 'report', 'note'],
            $events->pluck('type')->all(),
        );

        // Summaries carry the expected content.
        $test = $events->firstWhere('type', 'test');
        $this->assertSame('Test ORD-1', $test['title']);
        $this->assertStringContainsString('Imbalanced', $test['summary']);
        $this->assertStringContainsString('diversity 3.00', $test['summary']);

        $report = $events->firstWhere('type', 'report');
        $this->assertStringContainsString('Status: Published', $report['summary']);
        $this->assertStringContainsString('from test ORD-1', $report['summary']);

        $note = $events->firstWhere('type', 'note');
        $this->assertStringContainsString('7.60 kg', $note['summary']);
    }

    public function test_type_filter_narrows_the_feed(): void
    {
        [$pet] = $this->petWithEvents();

        $this->assertCount(2, PetTimeline::build($pet->fresh(), 'note'));
        $this->assertCount(1, PetTimeline::build($pet->fresh(), 'test'));
        $this->assertCount(1, PetTimeline::build($pet->fresh(), 'report'));

        $this->assertSame(['note', 'note'], PetTimeline::build($pet->fresh(), 'note')->pluck('type')->all());
    }

    public function test_date_range_filter_narrows_the_feed(): void
    {
        [$pet] = $this->petWithEvents();

        // Only the 2026-03-01 test + report fall in this window.
        $events = PetTimeline::build($pet->fresh(), null, '2026-02-01', '2026-04-01');

        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['test', 'report'], $events->pluck('type')->all());

        // Type AND date-range combine.
        $this->assertCount(1, PetTimeline::build($pet->fresh(), 'report', '2026-02-01', '2026-04-01'));
        $this->assertCount(0, PetTimeline::build($pet->fresh(), 'note', '2026-02-01', '2026-04-01'));
    }

    public function test_links_resolve_for_each_type(): void
    {
        [$pet, $test, $report] = $this->petWithEvents();

        $events = PetTimeline::build($pet->fresh());

        // Report: edit (admin) + view (public, new tab).
        $reportEvent = $events->firstWhere('type', 'report');
        $editLink = collect($reportEvent['links'])->firstWhere('label', 'Edit report');
        $viewLink = collect($reportEvent['links'])->firstWhere('label', 'View report');

        $this->assertSame(ReportResourceUrl($report), $editLink['url']);
        $this->assertFalse($editLink['newTab']);
        $this->assertSame($report->fresh()->report_url, $viewLink['url']);
        $this->assertTrue($viewLink['newTab']);

        // Test: links back to the pet hub (Tests section), same tab.
        $testEvent = $events->firstWhere('type', 'test');
        $this->assertSame(PetResource::getUrl('edit', ['record' => $pet]), $testEvent['links'][0]['url']);
        $this->assertFalse($testEvent['links'][0]['newTab']);

        // Notes: no out-links (managed in the Health Notes section).
        $noteEvent = $events->firstWhere('type', 'note');
        $this->assertSame([], $noteEvent['links']);
    }

    public function test_widget_renders_on_the_pet_hub_and_filters_live(): void
    {
        [$pet] = $this->petWithEvents();

        Livewire::test(PetTimelineWidget::class, ['record' => $pet])
            ->assertOk()
            ->assertSee('Timeline')
            ->assertSee('Test ORD-1')
            ->assertSee('Started kibble')
            // Apply the type filter → only the two notes remain.
            ->set('typeFilter', 'note')
            ->assertDontSee('Test ORD-1')
            ->assertSee('Started kibble');
    }
}

/** Local helper: the admin report-editor URL. */
function ReportResourceUrl(\App\Models\Report $report): string
{
    return \App\Filament\Resources\ReportResource::getUrl('edit', ['record' => $report]);
}
