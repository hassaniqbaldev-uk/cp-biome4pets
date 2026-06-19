<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\TestsAwaitingReportsTable;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dashboard refinement: the "tests awaiting reports" queue counts only tests with
 * no linked report (one with a report is excluded; one without is included).
 */
class DashboardAwaitingReportsTest extends TestCase
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

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{0:Test,1:Test} [awaiting, reported] */
    private function seedTests(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $awaiting = Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'ORD-AWAIT',
            'sample_id' => 'ORD-AWAIT',
            'report_date' => '2026-03-01',
        ]);

        $reported = Test::create([
            'pet_id' => $pet->id,
            'client_id' => $client->id,
            'order_id' => 'ORD-DONE',
            'sample_id' => 'ORD-DONE',
            'report_date' => '2026-03-02',
        ]);
        Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'test_id' => $reported->id,
            'status' => 'draft',
        ]);

        return [$awaiting, $reported];
    }

    public function test_awaiting_count_excludes_tests_that_already_have_a_report(): void
    {
        [$awaiting, $reported] = $this->seedTests();

        $count = Test::query()->whereDoesntHave('reports')->count();

        $this->assertSame(1, $count);
        $this->assertTrue($awaiting->reports()->doesntExist());
        $this->assertTrue($reported->reports()->exists());
    }

    public function test_queue_widget_lists_only_awaiting_tests(): void
    {
        [$awaiting, $reported] = $this->seedTests();

        Livewire::test(TestsAwaitingReportsTable::class)
            ->assertCanSeeTableRecords([$awaiting])
            ->assertCanNotSeeTableRecords([$reported]);
    }

    public function test_dashboard_renders_with_quick_actions(): void
    {
        $this->seedTests();

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('New report')
            ->assertSee('New client');
    }
}
