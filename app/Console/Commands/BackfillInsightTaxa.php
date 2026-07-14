<?php

namespace App\Console\Commands;

use App\Models\Test;
use App\Services\CsvParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stage 1 backfill for the deterministic health-insights rework.
 *
 * New reports get csv_data['insight_taxa'] automatically at generation (the parser
 * now emits it). EXISTING tests predate that key. This command reads each test's
 * RETAINED raw CSV (Test::csv_path on the 'local' disk), re-parses it, and writes
 * ONLY the insight_taxa map into the test's stored csv_data — every other csv_data
 * key, phylum_data and the report display are left untouched.
 *
 * SAFE + IDEMPOTENT by design:
 *   - Purely ADDITIVE: it sets one JSON key; re-running recomputes the same value.
 *   - GUARDED: a test whose CSV file is missing (very old records whose file was
 *     pruned) is skipped and counted, never errored — the run still completes.
 *   - Not auto-run and not destructive: it only fills the new field. Use --dry-run
 *     to preview counts without writing.
 *
 * An artisan command (not a migration) is the right tool here: it does file I/O and
 * re-parsing that must tolerate missing/oversized files and stay re-runnable — work
 * that does not belong in a one-shot schema migration.
 */
class BackfillInsightTaxa extends Command
{
    protected $signature = 'reports:backfill-insight-taxa
        {--dry-run : Report what would change without writing}
        {--test= : Backfill only the Test with this id}';

    protected $description = 'Populate csv_data[insight_taxa] on existing tests by re-parsing their retained CSV (Stage 1 health-insights data).';

    public function handle(CsvParserService $parser): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Test::query()->withTrashed()->whereNotNull('csv_path');
        if ($testId = $this->option('test')) {
            $query->whereKey($testId);
        }

        $updated = $missingFile = $noPath = $errors = $unchanged = 0;

        $query->chunkById(100, function ($tests) use ($parser, $dryRun, &$updated, &$missingFile, &$noPath, &$errors, &$unchanged): void {
            foreach ($tests as $test) {
                $path = $test->csv_path;

                // Guard: no path, or the retained file is gone (pruned very-old
                // records). Count and move on — never error the whole run.
                if (blank($path)) {
                    $noPath++;

                    continue;
                }
                if (! Storage::disk('local')->exists($path)) {
                    $missingFile++;
                    $this->warn("Test {$test->id}: CSV missing at {$path} — skipped.");

                    continue;
                }

                try {
                    $insightTaxa = $parser->parse(Storage::disk('local')->path($path))['insight_taxa'] ?? [];
                } catch (Throwable $e) {
                    $errors++;
                    $this->warn("Test {$test->id}: parse failed ({$e->getMessage()}) — skipped.");

                    continue;
                }

                $csvData = $test->csv_data ?? [];

                // Idempotent: if the stored map already equals the recomputed one,
                // there is nothing to write. Loose (==) on purpose — a JSON round
                // trip can turn a 0.0 back into an int 0, and a numeric re-store is
                // not a real change.
                if (($csvData['insight_taxa'] ?? null) == $insightTaxa) {
                    $unchanged++;

                    continue;
                }

                $csvData['insight_taxa'] = $insightTaxa;

                if (! $dryRun) {
                    // Update only this JSON column; touch nothing else on the test.
                    $test->forceFill(['csv_data' => $csvData])->saveQuietly();
                }

                $updated++;
            }
        });

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Backfill complete: {$verb} {$updated}, unchanged {$unchanged}, missing file {$missingFile}, no csv_path {$noPath}, parse errors {$errors}.");

        return self::SUCCESS;
    }
}
