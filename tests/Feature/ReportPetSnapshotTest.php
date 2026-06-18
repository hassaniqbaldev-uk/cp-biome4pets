<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\CreateReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1: a report freezes the pet's identity + health notes at generation time
 * (pet_snapshot), like subscription_snapshot. Editing the pet afterwards must NOT
 * change the saved report; reports with no snapshot fall back to the live Pet.
 */
class ReportPetSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated in-memory sqlite with the full schema — never touches MySQL.
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

    public function test_creating_a_report_freezes_pet_snapshot_from_the_pet(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $pet = Pet::create([
            'client_id' => $client->id,
            'name' => 'Biscuit',
            'breed' => 'Labrador',
            'diet' => 'Raw',
            'sex' => 'Female',
            'date_of_birth' => '2019-04-01',
        ]);
        // Health notes now live in the log; the stopgap health_notes accessor
        // Part 2 freezes the notes history as of the report date, dated/formatted.
        $pet->healthNotes()->create([
            'date' => '2026-06-17',
            'note' => 'Occasional loose stools; itchy skin in summer.',
        ]);

        // Drive the REAL create page's pre-create mutation (where the other
        // snapshots are frozen). phylum_data is supplied so it skips CSV re-parse.
        $page = new CreateReport();
        $method = new \ReflectionMethod($page, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);
        $data = $method->invoke($page, [
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => 'SNAP-1',
            'report_date' => '2026-06-17',
            'status' => 'draft',
            'phylum_data' => ['Firmicutes' => 50],
            'diversity_score' => 2.1,
        ]);

        $this->assertArrayHasKey('pet_snapshot', $data, 'create did not capture pet_snapshot');
        $this->assertSame([
            'name' => 'Biscuit',
            'breed' => 'Labrador',
            'diet' => 'Raw',
            'sex' => 'Female',
            'date_of_birth' => '2019-04-01',
            'health_notes' => '2026-06-17 · Occasional loose stools; itchy skin in summer.',
        ], $data['pet_snapshot']);

        // Persist and confirm it round-trips through the JSON cast.
        $report = Report::create($data);
        $this->assertSame('Biscuit', $report->fresh()->pet_snapshot['name']);
        $this->assertSame('2026-06-17 · Occasional loose stools; itchy skin in summer.', $report->fresh()->pet_snapshot['health_notes']);
    }

    public function test_editing_the_pet_after_creation_does_not_change_the_report_snapshot(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner2@example.com']);
        $pet = Pet::create([
            'client_id' => $client->id,
            'name' => 'Biscuit',
            'breed' => 'Labrador',
        ]);
        $pet->healthNotes()->create([
            'date' => '2026-06-17',
            'note' => 'Original notes at test time.',
        ]);

        $report = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => 'SNAP-2',
            'report_date' => '2026-06-17',
            'status' => 'draft',
            'pet_snapshot' => Report::buildPetSnapshot($pet->fresh()),
        ]);

        // The pet keeps living — owner renames it and logs a newer note later.
        $pet->update([
            'name' => 'Biscuit Renamed',
            'breed' => 'Golden Retriever',
        ]);
        $pet->healthNotes()->create([
            'date' => '2026-06-18',
            'note' => 'Completely different, current notes.',
        ]);

        $fresh = $report->fresh();

        // The frozen snapshot is unchanged — the whole point.
        $this->assertSame('Biscuit', $fresh->pet_snapshot['name']);
        $this->assertSame('Labrador', $fresh->pet_snapshot['breed']);
        $this->assertSame('2026-06-17 · Original notes at test time.', $fresh->pet_snapshot['health_notes']);

        // And the display accessor returns the FROZEN value, not the live pet's.
        $this->assertSame('Biscuit', $fresh->petField('name'));
        $this->assertSame('Labrador', $fresh->petField('breed'));
        $this->assertNotSame($pet->fresh()->name, $fresh->petField('name'));
    }

    public function test_report_with_null_snapshot_falls_back_to_live_pet(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner3@example.com']);
        $pet = Pet::create([
            'client_id' => $client->id,
            'name' => 'Mario',
            'breed' => 'Beagle',
        ]);

        // Simulates an old / sample report created before this feature.
        $report = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'sample_id' => 'OLD-1',
            'report_date' => '2026-06-17',
            'status' => 'draft',
            // pet_snapshot intentionally omitted (null).
        ]);

        $fresh = $report->fresh();
        $this->assertNull($fresh->pet_snapshot);

        // Falls back to the LIVE pet without error.
        $this->assertSame('Mario', $fresh->petField('name'));
        $this->assertSame('Beagle', $fresh->petField('breed'));

        // A field absent on the live pet returns the given default, no error.
        $this->assertNull($fresh->petField('diet'));
        $this->assertSame('—', $fresh->petField('diet', '—'));

        // With no snapshot AND no pet at all, the accessor still resolves to the
        // default (the view's `?: 'your dog'` then applies) — never an error.
        $orphan = Report::create([
            'client_id' => $client->id,
            'pet_id' => null,
            'sample_id' => 'OLD-2',
            'report_date' => '2026-06-17',
            'status' => 'draft',
        ]);
        $this->assertNull($orphan->fresh()->petField('name'));
        $this->assertSame('your dog', $orphan->fresh()->petField('name') ?: 'your dog');
    }

    public function test_build_pet_snapshot_returns_null_when_there_is_no_pet(): void
    {
        $this->assertNull(Report::buildPetSnapshot(null));
    }
}
