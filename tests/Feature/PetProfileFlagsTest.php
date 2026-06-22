<?php

namespace Tests\Feature;

use App\Filament\Forms\PetProfileFields;
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
 * The two new pet-profile flags: is_sensitive (plain stored boolean) and
 * is_large_breed (auto-driven by weight ≥ 35 kg, but staff-editable). Both save,
 * default to false, re-evaluate live on weight change, and show on the pet hub.
 */
class PetProfileFlagsTest extends TestCase
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

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_flags_default_false_and_cast_to_boolean(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex'])->fresh();

        $this->assertFalse($pet->is_sensitive);
        $this->assertFalse($pet->is_large_breed);
        $this->assertIsBool($pet->is_sensitive);

        $pet->update(['is_sensitive' => true, 'is_large_breed' => true]);
        $fresh = $pet->fresh();
        $this->assertTrue($fresh->is_sensitive);
        $this->assertTrue($fresh->is_large_breed);
    }

    public function test_create_form_saves_sensitive_flag(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);

        Livewire::test(CreatePet::class)
            ->fillForm([
                'client_id' => $client->id,
                'name' => 'Biscuit',
                'is_sensitive' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $pet = Pet::where('name', 'Biscuit')->firstOrFail();
        $this->assertTrue($pet->is_sensitive);
    }

    public function test_large_breed_auto_ticks_at_35kg_and_unticks_below_on_weight_change(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);

        $page = Livewire::test(CreatePet::class)
            ->fillForm(['client_id' => $client->id, 'name' => 'Biscuit']);

        // Weight changes re-evaluate the flag each time (weight is the source of truth).
        $page->set('data.initial_weight_kg', 40)->assertFormSet(['is_large_breed' => true]);   // >= 35 → ticks
        $page->set('data.initial_weight_kg', 20)->assertFormSet(['is_large_breed' => false]);  // < 35 → unticks
        $page->set('data.initial_weight_kg', 35)->assertFormSet(['is_large_breed' => true]);   // boundary (>= 35) → ticks
        $page->set('data.initial_weight_kg', 34.9)->assertFormSet(['is_large_breed' => false]); // just below → unticks
        $page->set('data.initial_weight_kg', null)->assertFormSet(['is_large_breed' => false]); // cleared → unticks

        $this->assertSame(35.0, PetProfileFields::LARGE_BREED_THRESHOLD_KG);
    }

    public function test_auto_ticked_large_breed_persists_through_create(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);

        Livewire::test(CreatePet::class)
            ->fillForm(['client_id' => $client->id, 'name' => 'Bruno'])
            ->set('data.initial_weight_kg', 42)        // auto-ticks is_large_breed
            ->assertFormSet(['is_large_breed' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Pet::where('name', 'Bruno')->firstOrFail()->is_large_breed);
    }

    public function test_pet_hub_displays_both_flags(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex', 'is_sensitive' => true, 'is_large_breed' => true]);

        Livewire::test(EditPet::class, ['record' => $pet->getKey()])
            ->assertSee('Sensitive animal')
            ->assertSee('Large breed');
    }
}
