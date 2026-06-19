<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource\Pages\CreatePet;
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
 * The Pet hub leads with a read-only header (the pet's key info at a glance),
 * with editing behind an explicit action, and the three tabs (Tests, Health
 * Notes, History) below. This guards the custom edit-page layout.
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

    private function seedPet(): Pet
    {
        $client = Client::create(['name' => 'Jane Owner', 'email' => 'o@e.com']);
        $pet = Pet::create([
            'client_id' => $client->id,
            'name' => 'Biscuit',
            'breed' => 'Labrador',
            'sex' => 'Male',
            'diet' => 'Raw',
            'date_of_birth' => '2022-03-12',
        ]);
        // Two weighed notes — the latest weight should win.
        $pet->healthNotes()->create(['date' => '2026-01-01', 'weight_kg' => 11.0, 'note' => 'old']);
        $pet->healthNotes()->create(['date' => '2026-06-01', 'weight_kg' => 12.5, 'note' => 'recent']);

        return $pet;
    }

    public function test_hub_shows_readonly_pet_header_at_a_glance_and_three_tabs(): void
    {
        $pet = $this->seedPet();

        Livewire::test(EditPet::class, ['record' => $pet->getRouteKey()])
            ->assertOk()
            // Default view is the read-only header (not the form).
            ->assertSet('editing', false)
            ->assertSee('Biscuit')        // name, prominent heading
            ->assertSee('Labrador')       // breed
            ->assertSee('Jane Owner')     // owner (linked to client hub)
            ->assertSee('Raw')            // diet
            ->assertSee('12.50 kg')       // latest weight (most recent weighed note)
            ->assertSee('Edit pet details')
            // Tabs still present and unchanged.
            ->assertSee('Tests')
            ->assertSee('Health Notes')
            ->assertSee('History');
    }

    public function test_edit_action_reveals_the_form_and_saves(): void
    {
        $pet = $this->seedPet();

        Livewire::test(EditPet::class, ['record' => $pet->getRouteKey()])
            ->assertSet('editing', false)
            ->call('editPetDetails')
            ->assertSet('editing', true)
            ->assertSee('Pet details')                     // the form section heading
            ->assertFormSet(['name' => 'Biscuit', 'breed' => 'Labrador'])
            ->set('data.breed', 'Beagle')
            ->call('save')
            ->assertSet('editing', false);                 // back to the read view

        $this->assertSame('Beagle', $pet->fresh()->breed);
    }

    public function test_cancel_leaves_edit_mode_without_saving(): void
    {
        $pet = $this->seedPet();

        Livewire::test(EditPet::class, ['record' => $pet->getRouteKey()])
            ->call('editPetDetails')
            ->set('data.breed', 'Poodle')
            ->call('cancelEdit')
            ->assertSet('editing', false);

        $this->assertSame('Labrador', $pet->fresh()->breed);
    }

    public function test_create_page_is_unaffected_full_form_with_initial_entry_fields(): void
    {
        Livewire::test(CreatePet::class)
            ->assertOk()
            ->assertSee('Pet details')
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('initial_note')        // create-only first health-log entry
            ->assertFormFieldExists('initial_weight_kg');
    }
}
