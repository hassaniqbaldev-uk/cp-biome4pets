<?php

namespace Tests\Feature;

use App\Filament\Resources\PetResource;
use App\Filament\Resources\PetResource\Pages\CreatePet;
use App\Filament\Resources\PetResource\RelationManagers\HealthNotesRelationManager;
use App\Models\Client;
use App\Models\Pet;
use App\Models\PetHealthNote;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Health-notes feature, Part 1: the dated pet_health_notes log, its model-level
 * "note or weight required" guard, the pet-creation first-entry capture, and the
 * stopgap health_notes accessor that keeps generation reading from the log until
 * Part 2 rewires it.
 */
class PetHealthNotesTest extends TestCase
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
    }

    private function pet(): Pet
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.com']);

        return Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
    }

    /** Drive the real CreatePet lifecycle (mutate-before-create + after-create). */
    private function createPetViaPage(array $formData): Pet
    {
        $page = new CreatePet();

        $mutate = new \ReflectionMethod($page, 'mutateFormDataBeforeCreate');
        $mutate->setAccessible(true);
        $data = $mutate->invoke($page, $formData);

        $pet = Pet::create($data);

        $recordProp = new \ReflectionProperty($page, 'record');
        $recordProp->setAccessible(true);
        $recordProp->setValue($page, $pet);

        $after = new \ReflectionMethod($page, 'afterCreate');
        $after->setAccessible(true);
        $after->invoke($page);

        return $pet->refresh();
    }

    public function test_creating_a_pet_with_an_initial_note_and_weight_creates_one_health_note(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o@e.com']);

        $pet = $this->createPetViaPage([
            'client_id' => $client->id,
            'name' => 'Rex',
            'initial_note' => 'Vomited twice this week.',
            'initial_weight_kg' => 12.34,
        ]);

        $this->assertSame(1, $pet->healthNotes()->count());

        $note = $pet->healthNotes()->first();
        $this->assertSame('Vomited twice this week.', $note->note);
        $this->assertSame('12.34', (string) $note->weight_kg);
        $this->assertSame(today()->toDateString(), $note->date->toDateString());

        // initial_* are transient: they must NOT be persisted on the pet itself.
        $this->assertArrayNotHasKey('initial_note', $pet->getAttributes());
        $this->assertArrayNotHasKey('initial_weight_kg', $pet->getAttributes());
    }

    public function test_creating_a_pet_with_a_weight_only_initial_entry_is_allowed(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o2@e.com']);

        $pet = $this->createPetViaPage([
            'client_id' => $client->id,
            'name' => 'Rex',
            'initial_weight_kg' => 9.5,
        ]);

        $this->assertSame(1, $pet->healthNotes()->count());
        $note = $pet->healthNotes()->first();
        $this->assertNull($note->note);
        $this->assertSame('9.50', (string) $note->weight_kg);
    }

    public function test_creating_a_pet_with_no_initial_entry_creates_no_note(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o3@e.com']);

        $pet = $this->createPetViaPage([
            'client_id' => $client->id,
            'name' => 'Rex',
        ]);

        $this->assertSame(0, $pet->healthNotes()->count());
    }

    public function test_a_pet_can_have_multiple_dated_notes_newest_first(): void
    {
        $pet = $this->pet();

        $pet->healthNotes()->create(['date' => '2026-01-01', 'note' => 'Oldest']);
        $pet->healthNotes()->create(['date' => '2026-06-01', 'note' => 'Newest']);
        $pet->healthNotes()->create(['date' => '2026-03-01', 'weight_kg' => 11.2]);

        $this->assertSame(3, $pet->healthNotes()->count());

        // Default relation order is newest-first.
        $this->assertSame(
            ['2026-06-01', '2026-03-01', '2026-01-01'],
            $pet->healthNotes->pluck('date')->map->toDateString()->all(),
        );
    }

    public function test_an_entry_with_neither_note_nor_weight_is_rejected(): void
    {
        $pet = $this->pet();

        $this->expectException(\InvalidArgumentException::class);
        $pet->healthNotes()->create(['date' => '2026-06-18']);
    }

    public function test_note_only_and_weight_only_entries_are_allowed(): void
    {
        $pet = $this->pet();

        $noteOnly = $pet->healthNotes()->create(['date' => '2026-06-18', 'note' => 'Itchy paws']);
        $weightOnly = $pet->healthNotes()->create(['date' => '2026-06-18', 'weight_kg' => 10.0]);

        $this->assertTrue($noteOnly->exists);
        $this->assertTrue($weightOnly->exists);
        $this->assertSame(2, PetHealthNote::count());
    }

    public function test_health_notes_for_context_formats_dated_weighted_history_oldest_first(): void
    {
        $pet = $this->pet();

        $pet->healthNotes()->create(['date' => '2026-01-10', 'weight_kg' => 7.2, 'note' => 'Started new kibble']);
        $pet->healthNotes()->create(['date' => '2026-03-02', 'weight_kg' => 7.6, 'note' => 'Stools firmer']);
        // Weight-only and note-only entries still render sensibly.
        $pet->healthNotes()->create(['date' => '2026-02-01', 'weight_kg' => 7.4]);
        $pet->healthNotes()->create(['date' => '2026-02-15', 'note' => 'Itchy paws']);

        // Oldest-first; each line is "date · weight · note" with missing parts omitted.
        $this->assertSame(
            "2026-01-10 · 7.20 kg · Started new kibble\n"
            . "2026-02-01 · 7.40 kg\n"
            . "2026-02-15 · Itchy paws\n"
            . "2026-03-02 · 7.60 kg · Stools firmer",
            $pet->fresh()->healthNotesForContext(),
        );

        // No entries at all → null (Phase 2 blank-handling still applies upstream).
        $this->assertNull($this->pet()->healthNotesForContext());
    }

    public function test_health_notes_relation_manager_is_registered_on_the_pet_hub(): void
    {
        $this->assertContains(
            HealthNotesRelationManager::class,
            PetResource::getRelations(),
        );
    }
}
