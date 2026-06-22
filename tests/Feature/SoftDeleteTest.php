<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Data safety: the core Client → Pet → Test → Report chain is soft-deletable, so a
 * normal admin delete is recoverable. Covers: soft delete hides but keeps the row,
 * restore works, the CSV survives a soft delete and only goes on force-delete, a
 * trashed report 404s publicly, counts exclude trashed, the Report→Test data proxy
 * survives a soft-deleted test, and soft-deleting a parent does NOT cascade.
 */
class SoftDeleteTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function chain(?string $csvPath = null): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-S', 'sample_id' => 'ORD-S',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_path' => $csvPath, 'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
        $report->steps()->create(['title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return compact('client', 'pet', 'test', 'report');
    }

    public function test_soft_delete_hides_but_does_not_destroy_and_restore_works(): void
    {
        ['report' => $report] = $this->chain();
        $id = $report->id;

        $report->delete();

        // Hidden from default queries, but the row survives (deleted_at stamped).
        $this->assertNull(Report::find($id));
        $this->assertNotNull(Report::withTrashed()->find($id));
        $this->assertTrue(Report::withTrashed()->find($id)->trashed());

        // Restore brings it back to the default scope.
        Report::withTrashed()->find($id)->restore();
        $this->assertNotNull(Report::find($id));
        $this->assertFalse(Report::find($id)->trashed());
    }

    public function test_csv_survives_soft_delete_and_only_deletes_on_force_delete(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('csv/lab.csv', "Phylum,%_hits\nFirmicutes,45");
        ['test' => $test] = $this->chain(csvPath: 'csv/lab.csv');

        // Soft delete keeps the CSV (the test, and its data, are recoverable).
        $test->delete();
        Storage::disk('local')->assertExists('csv/lab.csv');

        // Restore + the data is intact.
        $test->restore();
        Storage::disk('local')->assertExists('csv/lab.csv');

        // Force delete is the only thing that wipes the file.
        $test->forceDelete();
        Storage::disk('local')->assertMissing('csv/lab.csv');
    }

    public function test_soft_deleted_report_404s_publicly(): void
    {
        ['report' => $report] = $this->chain();
        $token = $report->public_token;

        $this->get('/report/'.$token)->assertOk();           // live → 200

        $report->delete();

        $this->get('/report/'.$token)->assertNotFound();     // trashed → 404
        $this->get('/report/'.$token.'/pdf')->assertNotFound();
        $this->get('/report/'.$token.'/subscribe')->assertNotFound();
    }

    public function test_counts_exclude_trashed_children(): void
    {
        ['pet' => $pet, 'test' => $test] = $this->chain();
        $pet->tests()->create([
            'client_id' => $pet->client_id, 'order_id' => 'ORD-2', 'sample_id' => 'ORD-2',
            'report_date' => '2026-06-18', 'csv_data' => ['phylum_totals' => []],
        ]);

        $this->assertSame(2, $pet->tests()->count());
        $test->delete();
        $this->assertSame(1, $pet->fresh()->tests()->count());                 // trashed excluded
        $this->assertSame(2, $pet->fresh()->tests()->withTrashed()->count());  // still there if asked
    }

    public function test_report_still_reads_data_from_a_soft_deleted_test(): void
    {
        ['test' => $test, 'report' => $report] = $this->chain();

        $test->delete();

        // The Report→Test proxy (test() is withTrashed) keeps resolving lab data.
        $fresh = Report::find($report->id);
        $this->assertSame(['Firmicutes' => 45, 'Bacteroidetes' => 25], $fresh->phylum_data);
        $this->assertSame(2.4, (float) $fresh->diversity_score);
        $this->assertNotNull($fresh->test);   // resolves the trashed parent
    }

    public function test_soft_deleting_a_parent_does_not_cascade_to_children(): void
    {
        ['client' => $client, 'pet' => $pet, 'test' => $test, 'report' => $report] = $this->chain();

        $client->delete();

        // No cascade: children stay active and queryable.
        $this->assertFalse($pet->fresh()->trashed());
        $this->assertFalse($test->fresh()->trashed());
        $this->assertFalse($report->fresh()->trashed());

        // And the pet still resolves its (now soft-deleted) client for display.
        $this->assertNotNull($pet->fresh()->client);
        $this->assertSame($client->id, $pet->fresh()->client->id);
        $this->assertTrue($pet->fresh()->client->trashed());
    }
}
