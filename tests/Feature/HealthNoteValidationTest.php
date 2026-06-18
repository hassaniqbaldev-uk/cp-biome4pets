<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\HealthNotesRelationManager;
use App\Models\Client;
use App\Models\Pet;
use App\Models\PetHealthNote;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bug fix: submitting a health-note entry with BOTH note and weight empty must
 * surface a clean inline form-validation error (required_without) and block the
 * save — it must NOT reach the model guard and throw a 500. Note-only and
 * weight-only entries still save.
 */
class HealthNoteValidationTest extends TestCase
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

    private function pet(): Pet
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.com']);

        return Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
    }

    private function manager(Pet $pet)
    {
        return Livewire::test(HealthNotesRelationManager::class, [
            'ownerRecord' => $pet,
            'pageClass' => EditPet::class,
        ]);
    }

    public function test_empty_entry_shows_inline_error_and_does_not_save_or_throw(): void
    {
        $pet = $this->pet();

        $this->manager($pet)
            ->callTableAction('create', data: [
                'date' => '2026-06-18',
                'note' => null,
                'weight_kg' => null,
            ])
            // Clean inline validation errors — no exception bubbles up.
            ->assertHasTableActionErrors(['note', 'weight_kg']);

        // Nothing was persisted (the save was blocked before the model guard).
        $this->assertSame(0, PetHealthNote::count());
    }

    public function test_note_only_entry_saves(): void
    {
        $pet = $this->pet();

        $this->manager($pet)
            ->callTableAction('create', data: [
                'date' => '2026-06-18',
                'note' => 'Itchy paws',
                'weight_kg' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $pet->healthNotes()->count());
        $this->assertSame('Itchy paws', $pet->healthNotes()->first()->note);
    }

    public function test_weight_only_entry_saves(): void
    {
        $pet = $this->pet();

        $this->manager($pet)
            ->callTableAction('create', data: [
                'date' => '2026-06-18',
                'note' => null,
                'weight_kg' => 10.5,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $pet->healthNotes()->count());
        $this->assertSame('10.50', (string) $pet->healthNotes()->first()->weight_kg);
    }
}
