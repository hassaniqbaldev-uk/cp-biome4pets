<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Reports list loads NEWEST-first by default (created_at desc), so the most
 * recently created reports are at the top. Column headers stay sortable, so this
 * only sets the INITIAL order — clicking a header still re-sorts.
 */
class ReportsListDefaultSortTest extends TestCase
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

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'super_admin', 'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Create a report with an explicit created_at so ordering is deterministic. */
    private function makeReport(string $petName, string $createdAt): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => $petName]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'draft',
            'pet_snapshot' => ['name' => $petName],
        ]);
        $report->forceFill(['created_at' => $createdAt])->save();

        return $report;
    }

    public function test_reports_list_defaults_to_newest_first(): void
    {
        $oldest = $this->makeReport('Oldest', '2026-01-01 09:00:00');
        $middle = $this->makeReport('Middle', '2026-03-15 09:00:00');
        $newest = $this->makeReport('Newest', '2026-06-30 09:00:00');

        Livewire::test(ListReports::class)
            ->assertOk()
            // Loaded newest-first: Newest before Middle before Oldest.
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_column_header_sorting_still_works(): void
    {
        $oldest = $this->makeReport('Oldest', '2026-01-01 09:00:00');
        $middle = $this->makeReport('Middle', '2026-03-15 09:00:00');
        $newest = $this->makeReport('Newest', '2026-06-30 09:00:00');

        // Clicking the Created header to sort ascending overrides the default.
        Livewire::test(ListReports::class)
            ->sortTable('created_at')                       // first click = ascending
            ->assertCanSeeTableRecords([$oldest, $middle, $newest], inOrder: true);
    }
}
