<?php

namespace Tests\Feature;

use App\Filament\Pages\BulkOperations;
use App\Models\BulkOperationRun;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bulk-run infrastructure is now operation-aware (regenerate | send | resend)
 * on the SAME bulk_regenerate_runs table — additive, so existing/regenerate rows
 * are unchanged. This covers the new columns/defaults, the generic succeeded
 * accessor, the operation-derived labels, and that the regenerate flow persists
 * operation='regenerate'.
 */
class BulkOperationRunTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_run_defaults_to_regenerate_operation_with_new_columns(): void
    {
        // A row created WITHOUT an operation (mirrors a historical/in-flight row).
        $run = BulkOperationRun::create([
            'started_by' => null, 'total' => 2, 'batch_ids' => [1, 2], 'remaining_ids' => [1, 2],
            'status' => BulkOperationRun::STATUS_RUNNING,
        ]);
        $run->refresh();

        $this->assertSame('regenerate', $run->operation);   // back-compat default
        $this->assertNull($run->channel);
        $this->assertSame(0, $run->skipped_count);
    }

    public function test_succeeded_accessor_reads_the_regenerated_counter(): void
    {
        $run = BulkOperationRun::create([
            'started_by' => null, 'total' => 5, 'batch_ids' => [], 'remaining_ids' => [],
            'regenerated_count' => 4, 'status' => BulkOperationRun::STATUS_COMPLETED,
        ]);

        $this->assertSame(4, $run->succeeded_count);   // generic name → same column
    }

    public function test_operation_labels_derive_from_the_operation(): void
    {
        $regen = new BulkOperationRun(['operation' => BulkOperationRun::OPERATION_REGENERATE]);
        $send = new BulkOperationRun(['operation' => BulkOperationRun::OPERATION_SEND]);
        $resend = new BulkOperationRun(['operation' => BulkOperationRun::OPERATION_RESEND]);

        $this->assertSame('bulk regeneration', $regen->operationLabel());
        $this->assertSame('bulk send', $send->operationLabel());
        $this->assertSame('bulk re-send', $resend->operationLabel());
    }

    public function test_regenerate_run_persists_operation_regenerate(): void
    {
        $admin = User::create([
            'name' => 'S', 'email' => 's'.uniqid().'@e.com',
            'role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make('x'),
        ]);
        $this->actingAs($admin);

        $client = Client::create(['name' => 'O', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-05-01', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);

        Livewire::test(BulkOperations::class)
            ->callTableBulkAction('regenerate', [$report->id])
            ->assertSet('operation', 'regenerate');

        $run = BulkOperationRun::firstOrFail();
        $this->assertSame('regenerate', $run->operation);
        $this->assertNull($run->channel);
        $this->assertSame(0, $run->skipped_count);
    }
}
