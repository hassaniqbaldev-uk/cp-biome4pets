<?php

namespace App\Console\Commands;

use App\Models\Test;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reconcile tests.sample_id with order_id for existing rows.
 *
 * sample_id is the value the Reports search matches, but historically it was only
 * mirrored from order_id at save time by the form/service — so rows created outside
 * that mirror were left with an empty (or divergent) sample_id and were unfindable by
 * their "Order / Test ID" until edited. The Test model now enforces the mirror on save
 * (Test::booted), and the Reports search also matches order_id directly — this command
 * cleans up the EXISTING rows so sample_id is correct everywhere it is used (search,
 * slug, report title, PDF filename), not just findable.
 *
 * SAFE + IDEMPOTENT:
 *   - READ-ONLY by default (and with --dry-run): reports what WOULD change, writes
 *     nothing. --force is required to write; --dry-run wins if both are passed.
 *   - Only sets sample_id = order_id where they differ (empty OR divergent); rows
 *     already consistent are skipped, so re-running changes nothing.
 *   - GUARDED: a row with a blank order_id is skipped (nothing to mirror from).
 *   - Writes ONLY the sample_id column via a raw update (no other data, no events);
 *     a bad row is caught and counted, never aborting the run.
 */
class BackfillSampleIdFromOrderId extends Command
{
    protected $signature = 'tests:backfill-sample-id
        {--force : Actually write the changes (default is a read-only preview)}
        {--dry-run : Force read-only even if --force is passed}';

    protected $description = 'Set tests.sample_id = order_id for rows where it is empty or diverges (read-only unless --force).';

    public function handle(): int
    {
        // Write only when explicitly forced AND not dry-run (dry-run wins for safety).
        $write = (bool) $this->option('force') && ! (bool) $this->option('dry-run');

        $wouldChange = $consistent = $noOrderId = $errors = $scanned = 0;
        $rows = [];

        Test::query()->withTrashed()->chunkById(200, function ($tests) use (
            $write, &$wouldChange, &$consistent, &$noOrderId, &$errors, &$scanned, &$rows
        ): void {
            foreach ($tests as $test) {
                $scanned++;

                try {
                    $order = trim((string) ($test->order_id ?? ''));
                    $sample = (string) ($test->sample_id ?? '');

                    // Guard: nothing to mirror from an empty order_id.
                    if ($order === '') {
                        $noOrderId++;

                        continue;
                    }

                    // Already consistent → skip (this is what makes re-runs a no-op).
                    if ($sample === $order) {
                        $consistent++;

                        continue;
                    }

                    // Empty OR divergent sample_id → reconcile to order_id.
                    $rows[] = [$test->id, $sample === '' ? '(empty)' : $sample, $order];

                    if ($write) {
                        // Raw update: touches ONLY sample_id (no updated_at, no events).
                        DB::table('tests')->where('id', $test->id)->update(['sample_id' => $order]);
                    }

                    $wouldChange++;
                } catch (Throwable $e) {
                    $errors++;
                    $this->warn("Test {$test->id}: skipped ({$e->getMessage()}).");
                }
            }
        });

        if ($rows !== []) {
            // Show the biggest surprises first: empties, then divergences.
            $this->table(['Test', 'sample_id (old)', 'order_id (→ new sample_id)'], array_slice($rows, 0, 200));
            if (count($rows) > 200) {
                $this->line('  … and '.(count($rows) - 200).' more.');
            }
        }

        $this->newLine();
        $this->info("Scanned {$scanned} test(s).");
        $verb = $write ? 'UPDATED' : 'would change';
        $this->line("  {$verb}: {$wouldChange}   already consistent: {$consistent}   skipped (no order_id): {$noOrderId}   errors: {$errors}");
        $this->newLine();

        if ($write) {
            $this->info('sample_id reconciled. Re-run to verify everything now reports as already consistent.');
        } else {
            $this->comment('READ-ONLY: nothing was written. Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }
}
