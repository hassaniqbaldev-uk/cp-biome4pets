<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The retest "Owner health update" free-text field — CAPTURE-ONLY (step: capture).
 * A single additive nullable column, shown in the admin form only on a retest, saved
 * like any fillable field, and NEVER fed into any AI prompt. First tests never see it
 * and store null; the public report/PDF are unchanged.
 */
class RetestOwnerHealthUpdateTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{0: Report, 1: Report} [firstTest, retest] for the same pet. */
    private function makeReports(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $mkTest = fn (): Test => Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-01-01',
        ]);

        $first = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $mkTest()->id, 'status' => 'draft',
        ]);
        $retest = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $mkTest()->id, 'status' => 'draft',
            'retest_of_report_id' => $first->id,
        ]);

        return [$first->fresh(), $retest->fresh()];
    }

    public function test_column_exists_is_nullable_and_defaults_null(): void
    {
        $this->assertTrue(Schema::hasColumn('reports', 'retest_owner_health_update'));

        [$first] = $this->makeReports();
        // An existing/first report created without the field is null (unaffected).
        $this->assertNull($first->retest_owner_health_update);
    }

    public function test_it_saves_as_a_fillable_field_on_create_and_update(): void
    {
        [, $retest] = $this->makeReports();

        $retest->update(['retest_owner_health_update' => 'Owner reports firmer stools and more energy.']);

        $this->assertSame(
            'Owner reports firmer stools and more energy.',
            $retest->fresh()->retest_owner_health_update,
        );
    }

    public function test_retest_edit_form_shows_the_field_and_persists_it(): void
    {
        [, $retest] = $this->makeReports();

        Livewire::test(EditReport::class, ['record' => $retest->getRouteKey()])
            ->assertFormFieldIsVisible('retest_owner_health_update')
            ->set('data.retest_owner_health_update', 'Itching resolved; appetite back to normal.')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'Itching resolved; appetite back to normal.',
            $retest->fresh()->retest_owner_health_update,
        );
    }

    public function test_first_test_hides_the_field_and_it_stays_null(): void
    {
        [$first] = $this->makeReports();

        Livewire::test(EditReport::class, ['record' => $first->getRouteKey()])
            ->assertFormFieldIsHidden('retest_owner_health_update')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($first->fresh()->retest_owner_health_update);
    }

    public function test_field_is_never_referenced_in_the_ai_generation_path(): void
    {
        // Capture-only: the column must not appear in any prompt-building path.
        foreach ([
            'app/Services/OpenAiService.php',
            'app/Support/ReportGeneration.php',
            'app/Support/PetFindings.php',
            'app/Support/RetestSummaryFacts.php',
        ] as $relative) {
            $this->assertStringNotContainsString(
                'retest_owner_health_update',
                file_get_contents(base_path($relative)),
                "{$relative} must not reference the capture-only field",
            );
        }
    }

    public function test_public_report_view_does_not_render_the_field(): void
    {
        [, $retest] = $this->makeReports();
        $retest->update(['retest_owner_health_update' => 'SECRET-OWNER-NOTE-XYZ', 'status' => 'published']);

        foreach (['report.show', 'report.pdf'] as $view) {
            $html = view($view, ['report' => $retest->fresh()->load([
                'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
            ])])->render();
            $this->assertStringNotContainsString('SECRET-OWNER-NOTE-XYZ', $html, "{$view} must not show the capture-only field");
        }
    }
}
