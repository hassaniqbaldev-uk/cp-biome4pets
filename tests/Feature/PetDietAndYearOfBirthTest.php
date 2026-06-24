<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource\Pages\CreatePet;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Models\Client;
use App\Models\Pet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Home Cooked" diet option + the year-of-birth form change. The DB column
 * (date_of_birth, a date) is unchanged: year-only entry is stored as YYYY-01-01,
 * and existing full-date pets display + round-trip as just their year.
 */
class PetDietAndYearOfBirthTest extends TestCase
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

    private function client(): Client
    {
        return Client::create(['name' => 'Owner', 'email' => 'o@e.com']);
    }

    public function test_home_cooked_diet_is_selectable_and_saves(): void
    {
        $client = $this->client();

        Livewire::test(CreatePet::class)
            ->fillForm([
                'client_id' => $client->id,
                'name' => 'Biscuit',
                'diet' => 'Home Cooked',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $pet = Pet::where('name', 'Biscuit')->firstOrFail();
        $this->assertSame('Home Cooked', $pet->diet);

        // Home Cooked is just another diet value — it does NOT trigger the
        // Kibble-only nutritionist CTA (only 'Kibble' does).
        $report = new \App\Models\Report();
        $report->pet_snapshot = ['diet' => 'Home Cooked'];
        $this->assertFalse($report->recommendsNutritionist());

        $kibble = new \App\Models\Report();
        $kibble->pet_snapshot = ['diet' => 'Kibble'];
        $this->assertTrue($kibble->recommendsNutritionist());
    }

    public function test_year_of_birth_saves_as_first_of_january(): void
    {
        $client = $this->client();

        Livewire::test(CreatePet::class)
            ->fillForm([
                'client_id' => $client->id,
                'name' => 'Rex',
                'date_of_birth' => '2021',   // year-only selection
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $pet = Pet::where('name', 'Rex')->firstOrFail();

        // Stored as a real date (column type unchanged) = 1st of January of the year.
        $this->assertInstanceOf(Carbon::class, $pet->date_of_birth);
        $this->assertSame('2021-01-01', $pet->date_of_birth->format('Y-m-d'));
        $this->assertSame(2021, $pet->birthYear());
    }

    public function test_existing_full_date_pet_loads_and_displays_as_its_year(): void
    {
        $client = $this->client();
        // A pet created BEFORE this change, with a full DOB.
        $pet = Pet::create([
            'client_id' => $client->id, 'name' => 'Bruno', 'date_of_birth' => '2019-04-17',
        ]);

        // The model helpers reduce the full date to the year.
        $this->assertSame(2019, $pet->birthYear());
        $this->assertStringStartsWith('2019', $pet->birthYearLabel());

        // The edit form's year select is pre-set to the stored year (formatStateUsing),
        // proving existing dated pets round-trip cleanly into the year dropdown.
        Livewire::test(EditPet::class, ['record' => $pet->getKey()])
            ->call('editPetDetails')
            ->assertFormSet(['date_of_birth' => '2019']);
    }

    public function test_pet_hub_shows_the_year_not_the_full_date(): void
    {
        $client = $this->client();
        $pet = Pet::create([
            'client_id' => $client->id, 'name' => 'Bella', 'date_of_birth' => '2020-07-09',
        ]);

        Livewire::test(EditPet::class, ['record' => $pet->getKey()])
            ->assertSee('2020')
            ->assertSee('Year of birth')
            ->assertDontSee('9 Jul 2020');   // the old full-date format is gone
    }
}
