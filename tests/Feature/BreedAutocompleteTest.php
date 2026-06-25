<?php

namespace Tests\Feature;

use App\Models\Breed;
use App\Models\Client;
use App\Models\Pet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The breed autocomplete is backed by a managed `breeds` lookup table that powers
 * suggestions + case-insensitive dedup. The pet's breed stays a plain TEXT column —
 * the table never becomes a foreign key and no pet breed is ever changed.
 */
class BreedAutocompleteTest extends TestCase
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
    }

    private function client(): Client
    {
        return Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
    }

    /** Insert pets straight into the table, bypassing the model's saved hook, so the
     *  breeds table reflects ONLY what the migration seed produces. */
    private function rawPet(?string $breed): void
    {
        DB::table('pets')->insert([
            'client_id' => $this->client()->id, 'name' => 'P', 'breed' => $breed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runSeed(): void
    {
        DB::table('breeds')->delete();
        $migration = require database_path('migrations/2026_06_28_000000_create_breeds_table.php');
        $method = (new \ReflectionClass($migration))->getMethod('seedFromExistingPets');
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    // ── The defensive seed (live data is messier than local) ─────────────────
    public function test_seed_collects_distinct_breeds_and_handles_messy_values(): void
    {
        $this->rawPet('French Bulldog');
        $this->rawPet(' frenchie ');   // whitespace → trimmed
        $this->rawPet('FRENCHIE');     // case variant of "frenchie"
        $this->rawPet('Labrador');
        $this->rawPet('Labrador');     // exact dup
        $this->rawPet('');             // blank → skipped
        $this->rawPet('   ');          // whitespace-only → skipped
        $this->rawPet(null);           // null → skipped

        $this->runSeed();

        $names = Breed::orderBy('name')->pluck('name')->all();
        // French Bulldog, one "frenchie" (first-seen casing, trimmed), one Labrador.
        $this->assertEqualsCanonicalizing(['French Bulldog', 'Labrador', 'frenchie'], $names);
        $this->assertSame(3, Breed::count());
        // No blanks leaked in.
        $this->assertSame(0, Breed::whereRaw("TRIM(name) = ''")->count());
        // type defaults to 'dog' for the future hierarchy.
        $this->assertSame('dog', Breed::first()->type);
    }

    public function test_seed_is_idempotent(): void
    {
        $this->rawPet('Pug');
        $this->rawPet('Beagle');

        $this->runSeed();
        $this->runSeed();   // second run must not duplicate

        $this->assertSame(2, Breed::count());
    }

    // ── The model: case-insensitive find-or-create ───────────────────────────
    public function test_find_or_create_is_case_insensitive_and_skips_blank(): void
    {
        $a = Breed::findOrCreateByName('French Bulldog');
        $b = Breed::findOrCreateByName('  french bulldog ');   // same breed, different case + spaces

        $this->assertNotNull($a);
        $this->assertTrue($a->is($b));             // reused, not duplicated
        $this->assertSame(1, Breed::count());
        $this->assertSame('French Bulldog', $a->name); // original casing kept

        $this->assertNull(Breed::findOrCreateByName(''));
        $this->assertNull(Breed::findOrCreateByName('   '));
        $this->assertNull(Breed::findOrCreateByName(null));
        $this->assertSame(1, Breed::count());      // blanks created nothing
    }

    public function test_search_names_matches_case_insensitively(): void
    {
        Breed::findOrCreateByName('French Bulldog');
        Breed::findOrCreateByName('Labrador');

        $this->assertArrayHasKey('French Bulldog', Breed::searchNames('french'));
        $this->assertArrayHasKey('French Bulldog', Breed::searchNames('BULL'));
        $this->assertArrayNotHasKey('Labrador', Breed::searchNames('french'));
    }

    // ── Saving a pet feeds the list (any path), without changing the pet ──────
    public function test_saving_a_pet_adds_its_breed_to_the_list_and_keeps_the_string(): void
    {
        $pet = Pet::create(['client_id' => $this->client()->id, 'name' => 'Rex', 'breed' => 'Vizsla']);

        // The pet keeps its breed string exactly.
        $this->assertSame('Vizsla', $pet->fresh()->breed);
        // ...and it's now a suggestion.
        $this->assertSame(1, Breed::whereRaw('LOWER(name) = ?', ['vizsla'])->count());

        // A second pet with a case variant must NOT create a duplicate breed.
        Pet::create(['client_id' => $this->client()->id, 'name' => 'Bella', 'breed' => 'vizsla']);
        $this->assertSame(1, Breed::whereRaw('LOWER(name) = ?', ['vizsla'])->count());
    }

    public function test_existing_pet_breed_is_never_modified(): void
    {
        // A pet whose stored breed isn't (yet) tidy stays exactly as stored.
        $pet = Pet::create(['client_id' => $this->client()->id, 'name' => 'Old', 'breed' => 'frenchie']);
        Breed::findOrCreateByName('French Bulldog');   // a tidy entry also exists

        $this->assertSame('frenchie', $pet->fresh()->breed);   // untouched
    }

    public function test_a_pet_with_no_breed_adds_nothing(): void
    {
        Pet::create(['client_id' => $this->client()->id, 'name' => 'NoBreed', 'breed' => null]);
        $this->assertSame(0, Breed::count());
    }

    // ── The field: a custom type-or-pick combobox, no Select/createOption popup ──
    public function test_breed_field_is_the_custom_autocomplete_not_a_select_popup(): void
    {
        Breed::findOrCreateByName('French Bulldog');
        Breed::findOrCreateByName('Labrador');

        $field = \App\Filament\Forms\PetProfileFields::breed();

        // The custom combobox field — NOT a Select, so there is structurally no
        // createOption "+" / popup flow.
        $this->assertInstanceOf(\App\Filament\Forms\Components\BreedAutocomplete::class, $field);
        $this->assertNotInstanceOf(\Filament\Forms\Components\Select::class, $field);
        $this->assertSame('breed', $field->getName());
        $this->assertSame('filament.forms.components.breed-autocomplete', $field->getView());

        // Its dropdown is fed by the managed breed list.
        $breeds = $field->getBreeds();
        $this->assertContains('French Bulldog', $breeds);
        $this->assertContains('Labrador', $breeds);
    }

    public function test_typing_a_new_breed_saves_it_and_feeds_the_suggestions(): void
    {
        // Free-typed text lands on the pet; the saved hook folds it into the list
        // (no create modal), so it's a suggestion next time.
        $pet = Pet::create(['client_id' => $this->client()->id, 'name' => 'New', 'breed' => 'Cockapoo']);

        $this->assertSame('Cockapoo', $pet->fresh()->breed);
        $this->assertSame(1, Breed::whereRaw('LOWER(name) = ?', ['cockapoo'])->count());
        $this->assertContains('Cockapoo', \App\Filament\Forms\PetProfileFields::breed()->getBreeds());
    }

    public function test_all_three_pet_forms_use_the_shared_breed_field(): void
    {
        $forms = [
            'app/Filament/Resources/PetResource.php',
            'app/Filament/Resources/ReportResource.php',
            'app/Filament/Resources/ClientResource/RelationManagers/PetsRelationManager.php',
        ];
        foreach ($forms as $form) {
            $src = file_get_contents(base_path($form));
            $this->assertStringContainsString('PetProfileFields::breed()', $src, "{$form} must use the shared breed field");
            // The old plain text input / datalist must be gone from the breed field.
            $this->assertStringNotContainsString("TextInput::make('breed')", $src, "{$form} still has a plain breed TextInput");
        }
    }
}
