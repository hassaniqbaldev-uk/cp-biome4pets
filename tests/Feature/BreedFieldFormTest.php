<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource\Pages\CreatePet;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Models\Breed;
use App\Models\Client;
use App\Models\Pet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The custom breed combobox bound inside real Filament forms: it renders, its value
 * persists on save (typed OR picked), pre-fills on edit, and folds new breeds into
 * the lookup table — all on the standard `breed` state path (no popup).
 */
class BreedFieldFormTest extends TestCase
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
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function client(): Client
    {
        return Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
    }

    public function test_create_form_renders_the_custom_breed_field(): void
    {
        Breed::findOrCreateByName('French Bulldog');

        Livewire::test(CreatePet::class)
            ->assertOk()
            // The custom component's view + helper text render (not a datalist/select).
            ->assertSee('Start typing to pick an existing breed')
            ->assertSeeHtml('fi-fo-breed-autocomplete');
    }

    public function test_typed_new_breed_persists_on_create_and_joins_the_list(): void
    {
        $client = $this->client();

        Livewire::test(CreatePet::class)
            ->fillForm(['client_id' => $client->id, 'name' => 'Rex', 'breed' => 'Whippet'])
            ->call('create')
            ->assertHasNoFormErrors();

        $pet = Pet::where('name', 'Rex')->firstOrFail();
        $this->assertSame('Whippet', $pet->breed);                               // saved as a string
        $this->assertSame(1, Breed::whereRaw('LOWER(name) = ?', ['whippet'])->count()); // added to list
    }

    public function test_selecting_an_existing_breed_persists_without_duplicating(): void
    {
        $client = $this->client();
        Breed::findOrCreateByName('Labrador');

        Livewire::test(CreatePet::class)
            ->fillForm(['client_id' => $client->id, 'name' => 'Bella', 'breed' => 'Labrador'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Labrador', Pet::where('name', 'Bella')->first()->breed);
        $this->assertSame(1, Breed::whereRaw('LOWER(name) = ?', ['labrador'])->count()); // no dup
    }

    public function test_edit_form_prefills_the_existing_breed(): void
    {
        $pet = Pet::create(['client_id' => $this->client()->id, 'name' => 'Biscuit', 'breed' => 'French Bulldog']);

        Livewire::test(EditPet::class, ['record' => $pet->getKey()])
            ->call('editPetDetails')
            ->assertFormSet(['breed' => 'French Bulldog']);   // round-trips on the standard state path
    }

    public function test_editing_to_a_new_breed_persists_and_adds_it(): void
    {
        $pet = Pet::create(['client_id' => $this->client()->id, 'name' => 'Old', 'breed' => 'frenchie']);

        Livewire::test(EditPet::class, ['record' => $pet->getKey()])
            ->call('editPetDetails')
            ->fillForm(['breed' => 'French Bulldog'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('French Bulldog', $pet->fresh()->breed);
        $this->assertSame(1, Breed::whereRaw('LOWER(name) = ?', ['french bulldog'])->count());
    }
}
