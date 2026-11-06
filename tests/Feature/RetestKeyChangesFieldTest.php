<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Support\ReportGeneration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin Key Changes editor (step 7c). Two repeaters mapped to
 * retest_key_changes.{increases,decreases}, shown only for a retest, prefilled from
 * Report::keyChanges(), and persisted on save (surviving regenerate via retestFreezePayload).
 * Uses the real Teddy fixtures.
 */
class RetestKeyChangesFieldTest extends TestCase
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

        $this->actingAs(\App\Models\User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'password' => bcrypt('x')]));
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

    /** An original + a linked, frozen retest (both published), for the same pet. */
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
        $retest->update(['status' => 'published']);

        return [$retest->fresh(), $original];
    }

    /** Names in a repeater's (UUID-keyed) form state. */
    private function repeaterNames(array $state): array
    {
        return array_values(array_map(fn ($row) => $row['name'] ?? null, array_values($state)));
    }

    public function test_editor_is_visible_and_prefills_with_the_default_for_a_retest(): void
    {
        [$retest] = $this->seedLinkedRetest();

        $component = Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->assertFormFieldIsVisible('retest_key_changes.increases')
            ->assertFormFieldIsVisible('retest_key_changes.decreases');

        $inc = $this->repeaterNames($component->get('data.retest_key_changes.increases') ?? []);
        $dec = $this->repeaterNames($component->get('data.retest_key_changes.decreases') ?? []);

        $this->assertContains('Fusobacterium', $inc);
        $this->assertContains('Streptococcus', $inc);
        $this->assertContains('Bacteroides', $dec);
        $this->assertContains('Blautia', $dec);
    }

    public function test_editing_and_saving_persists_and_the_report_renders_the_edit(): void
    {
        [$retest] = $this->seedLinkedRetest();

        // Rename to a single curated row per side (removing the rest, changing labels).
        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->set('data.retest_key_changes.increases', [
                ['name' => 'Curated Riser', 'original_pct' => 0.2, 'retest_pct' => 4.0, 'label' => 'Marked increase'],
            ])
            ->set('data.retest_key_changes.decreases', [
                ['name' => 'Curated Faller', 'original_pct' => 8.0, 'retest_pct' => 0.0, 'label' => 'Resolved'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $retest->fresh()->retest_key_changes;
        $this->assertSame(['Curated Riser'], array_column($stored['increases'], 'name'));
        $this->assertSame(['Curated Faller'], array_column($stored['decreases'], 'name'));
        // The seeded default rows are gone.
        $this->assertNotContains('Fusobacterium', array_column($stored['increases'], 'name'));

        // The published report now renders the edited rows, not the default.
        $html = $this->get(route('report.show', $retest->fresh()->public_token))->assertOk()->getContent();
        $this->assertStringContainsString('Curated Riser', $html);
        $this->assertStringContainsString('Curated Faller', $html);
        $this->assertStringNotContainsString('Fusobacterium', $html);
    }

    public function test_edit_survives_a_regenerate(): void
    {
        [$retest] = $this->seedLinkedRetest();

        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->set('data.retest_key_changes.increases', [
                ['name' => 'Sticky Row', 'original_pct' => 1.0, 'retest_pct' => 2.0, 'label' => 'Increased'],
            ])
            ->set('data.retest_key_changes.decreases', [])
            ->call('save')
            ->assertHasNoFormErrors();

        ReportGeneration::regenerateReport($retest->fresh());

        $stored = $retest->fresh()->retest_key_changes;
        $this->assertSame(['Sticky Row'], array_column($stored['increases'], 'name'));
    }

    public function test_editor_is_hidden_for_a_non_retest_report(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $test = $this->makeTest($pet, $client, 'teddy_original_S9.csv', 'retest/o'.uniqid().'.csv');
        $report = ReportGeneration::createReportFromTest($test->fresh())->fresh();

        $this->assertFalse($report->isRetest());

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertFormFieldIsHidden('retest_key_changes.increases')
            ->assertFormFieldIsHidden('retest_key_changes.decreases')
            ->call('save')
            ->assertHasNoFormErrors();

        // Still a non-retest with no key changes written.
        $this->assertNull($report->fresh()->retest_key_changes);
    }
}
