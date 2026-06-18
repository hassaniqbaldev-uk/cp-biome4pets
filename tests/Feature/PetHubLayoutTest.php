<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Models\Client;
use App\Models\Pet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Pet hub renders a compact collapsible form plus the three tabs (Tests,
 * Health Notes, History) that host the two relation managers and the timeline
 * widget. This guards the custom edit-page layout.
 */
class PetHubLayoutTest extends TestCase
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

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_pet_hub_renders_form_section_and_three_tabs(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        Livewire::test(EditPet::class, ['record' => $pet->getRouteKey()])
            ->assertOk()
            ->assertSee('Pet details')   // collapsible form section heading
            ->assertSee('Tests')
            ->assertSee('Health Notes')
            ->assertSee('History');
    }
}
