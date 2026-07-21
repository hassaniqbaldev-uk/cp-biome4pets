<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Part C: tests:backfill-sample-id reconciles existing rows — sets sample_id = order_id
 * where empty or divergent, skips already-consistent rows and rows with no order_id, is
 * idempotent, read-only unless --force, and touches only sample_id.
 */
class BackfillSampleIdTest extends TestCase
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

    /** Raw-insert a Test (bypassing the model hook) so we can seed legacy divergent rows. */
    private function rawTest(string $orderId, string $sampleId): int
    {
        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'P']);

        return DB::table('tests')->insertGetId([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => $orderId, 'sample_id' => $sampleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sampleId(int $id): ?string
    {
        return DB::table('tests')->where('id', $id)->value('sample_id');
    }

    public function test_force_reconciles_empty_and_divergent_rows_only(): void
    {
        $empty = $this->rawTest('4339', '');                 // empty  → should become 4339
        $divergent = $this->rawTest('5000', 'OLD-VALUE');    // differs → should become 5000
        $consistent = $this->rawTest('6000', '6000');        // already ok → untouched
        $noOrder = $this->rawTest('', '');                   // no order_id → skipped

        Artisan::call('tests:backfill-sample-id', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame('4339', $this->sampleId($empty));
        $this->assertSame('5000', $this->sampleId($divergent));
        $this->assertSame('6000', $this->sampleId($consistent));
        $this->assertSame('', $this->sampleId($noOrder));    // guarded, unchanged

        $this->assertStringContainsString('UPDATED: 2', $output);
        $this->assertStringContainsString('already consistent: 1', $output);
        $this->assertStringContainsString('skipped (no order_id): 1', $output);
    }

    public function test_read_only_by_default_writes_nothing(): void
    {
        $empty = $this->rawTest('4339', '');

        Artisan::call('tests:backfill-sample-id');           // no --force
        $output = Artisan::output();

        $this->assertSame('', $this->sampleId($empty), 'nothing should be written without --force');
        $this->assertStringContainsString('would change: 1', $output);
        $this->assertStringContainsString('nothing was written', $output);
    }

    public function test_dry_run_wins_even_if_force_is_passed(): void
    {
        $empty = $this->rawTest('4339', '');

        Artisan::call('tests:backfill-sample-id', ['--force' => true, '--dry-run' => true]);

        $this->assertSame('', $this->sampleId($empty), '--dry-run must override --force');
    }

    public function test_is_idempotent(): void
    {
        $this->rawTest('4339', '');

        Artisan::call('tests:backfill-sample-id', ['--force' => true]);   // first pass fixes it
        Artisan::call('tests:backfill-sample-id', ['--force' => true]);   // second pass: nothing left
        $output = Artisan::output();

        $this->assertStringContainsString('UPDATED: 0', $output);
        $this->assertStringContainsString('already consistent: 1', $output);
    }

    public function test_only_the_sample_id_column_is_touched(): void
    {
        $id = $this->rawTest('4339', '');
        $before = DB::table('tests')->where('id', $id)->first();

        Artisan::call('tests:backfill-sample-id', ['--force' => true]);

        $after = DB::table('tests')->where('id', $id)->first();
        $this->assertSame('4339', $after->sample_id);
        // Everything else (incl. updated_at — raw update) is unchanged.
        $this->assertSame($before->order_id, $after->order_id);
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertSame($before->created_at, $after->created_at);
    }
}
