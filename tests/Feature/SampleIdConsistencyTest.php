<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Reports search "4339" bug: a record was unfindable by its Order / Test ID until
 * opened+saved, because the Reports search matched only test.sample_id and sample_id
 * was a save-time mirror of order_id left empty on some rows.
 *
 * Part A — the Reports search also matches test.order_id (the value admins enter).
 * Part B — the Test model mirrors sample_id ← order_id on save when blank, so the
 *          field can never be left empty again.
 */
class SampleIdConsistencyTest extends TestCase
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

    private function petAndClient(): array
    {
        $client = Client::create(['name' => 'Jane', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        return [$client, $pet];
    }

    /** Insert a "legacy" Test straight into the DB, bypassing the model hook, so we can
     *  simulate a row whose sample_id was never mirrored (empty) while order_id is set. */
    private function rawInsertTest(int $petId, int $clientId, string $orderId, string $sampleId): int
    {
        return DB::table('tests')->insertGetId([
            'pet_id' => $petId, 'client_id' => $clientId,
            'order_id' => $orderId, 'sample_id' => $sampleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Part A: the Reports search matches order_id ──────────────────────────

    public function test_reports_searchable_attributes_include_order_id(): void
    {
        $attrs = ReportResource::getGloballySearchableAttributes();

        $this->assertContains('test.order_id', $attrs);
        $this->assertContains('test.sample_id', $attrs); // kept
    }

    public function test_report_is_found_by_order_id_even_when_sample_id_is_empty(): void
    {
        [$client, $pet] = $this->petAndClient();
        // The legacy shape: order_id "4339", sample_id "" (never mirrored).
        $testId = $this->rawInsertTest($pet->id, $client->id, '4339', '');
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $testId,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);

        // Global search by the Order / Test ID finds the report — no edit needed.
        $results = ReportResource::getGlobalSearchResults('4339');

        $this->assertGreaterThan(0, $results->count(), 'searching order_id must find the report');
        // Confirm at the data layer too (independent of Filament result URLs).
        $this->assertTrue(
            Report::whereHas('test', fn ($q) => $q->where('order_id', 'like', '%4339%'))->whereKey($report->id)->exists(),
        );
    }

    // ── Part B: the model hook mirrors sample_id ← order_id when blank ────────

    public function test_hook_fills_blank_sample_id_from_order_id_on_create(): void
    {
        [$client, $pet] = $this->petAndClient();

        // Create WITHOUT a sample_id at all — the hook fills it (and prevents the
        // NOT NULL violation that would otherwise occur).
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => '4339',
        ]);

        $this->assertSame('4339', $test->sample_id);
        $this->assertSame('4339', $test->fresh()->sample_id);
    }

    public function test_hook_refills_a_blanked_sample_id_on_update(): void
    {
        [$client, $pet] = $this->petAndClient();
        $test = Test::create(['pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => '4339']);

        // Blank it, then save → the hook refills from order_id.
        $test->sample_id = '';
        $test->save();

        $this->assertSame('4339', $test->fresh()->sample_id);
    }

    public function test_hook_does_not_overwrite_a_deliberately_different_sample_id(): void
    {
        [$client, $pet] = $this->petAndClient();

        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => '4339', 'sample_id' => 'LAB-SAMPLE-X',
        ]);

        $this->assertSame('LAB-SAMPLE-X', $test->fresh()->sample_id, 'a set sample_id must be preserved');
    }

    public function test_hook_leaves_sample_id_blank_when_order_id_is_also_blank(): void
    {
        [$client, $pet] = $this->petAndClient();

        // No order_id to mirror from → the guard leaves sample_id empty (no error).
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => '', 'sample_id' => '',
        ]);

        $this->assertSame('', $test->fresh()->sample_id);
    }
}
