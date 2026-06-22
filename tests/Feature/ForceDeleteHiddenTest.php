<?php

namespace Tests\Feature;

use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Force-delete (permanent) is NOT exposed in the UI for ANY role — the panel only
 * ever soft-deletes (recoverable). The Restore action + soft Delete remain.
 * Asserted as a Super Admin (the highest role) — if it's gone for them, it's gone
 * for everyone.
 */
class ForceDeleteHiddenTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs(User::create([
            'name' => 'Boss', 'email' => 'boss@e.com',
            'role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make('secret'),
        ]));
    }

    public function test_clients_list_has_no_force_delete_action(): void
    {
        Livewire::test(ListClients::class)
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('forceDelete')
            // Recoverable delete + restore remain.
            ->assertTableActionExists('restore')
            ->assertTableBulkActionExists('delete')
            ->assertTableBulkActionExists('restore');
    }

    public function test_reports_list_has_no_force_delete_action(): void
    {
        Livewire::test(ListReports::class)
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('forceDelete')
            ->assertTableActionExists('delete')
            ->assertTableActionExists('restore');
    }

    public function test_soft_delete_then_restore_still_works_via_the_model(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);

        $client->delete();
        $this->assertNull(Client::find($client->id));            // hidden (soft-deleted)
        $this->assertNotNull(Client::withTrashed()->find($client->id)); // but recoverable

        Client::withTrashed()->find($client->id)->restore();
        $this->assertNotNull(Client::find($client->id));         // back
    }
}
