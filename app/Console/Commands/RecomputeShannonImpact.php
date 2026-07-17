<?php

namespace App\Console\Commands;

use App\Models\Test;
use App\Services\CsvParserService;
use App\Support\ReportContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * READ-ONLY impact assessment for the Shannon diversity fix (report-4050 bug).
 *
 * The old parser underestimated Shannon whenever part of a sample's abundance sat in
 * rows that only classify to genus/family (see CsvParserService::shannon). Stored
 * reports therefore may carry a too-low diversity score — and because
 * ReportContent::classify() gates on diversity < 1.9 and the display bands cut at
 * 1.9 / 2.5, a too-low score can also mean a WORSE diversity band and a WORSE
 * microbiome classification shown to a customer.
 *
 * This command recomputes Shannon for every test from its RETAINED CSV using the
 * FIXED method and REPORTS what would change. It is strictly read-only:
 *   - it NEVER writes to the database or the CSVs (no --force, by design);
 *   - a test whose CSV file is missing (very old records) is skipped and counted;
 *   - a parse failure is caught and counted, never aborting the run.
 *
 * Run it to see the blast radius before deciding whether/how to re-store values.
 */
class RecomputeShannonImpact extends Command
{
    protected $signature = 'reports:recompute-shannon-impact
        {--all : List every test, not just the ones whose value changes}
        {--threshold=0.005 : Minimum absolute change to count as changed}';

    protected $description = 'READ-ONLY: recompute Shannon from retained CSVs and report which reports would change (value, band, classification). Writes nothing.';

    public function handle(CsvParserService $parser): int
    {
        $showAll = (bool) $this->option('all');
        $threshold = (float) $this->option('threshold');

        $rows = [];
        $changed = $bandChanged = $classChanged = 0;
        $missingFile = $noPath = $errors = $unchanged = $scanned = 0;

        Test::query()
            ->withTrashed()
            ->whereNotNull('csv_path')
            ->with('reports')
            ->chunkById(100, function ($tests) use (
                $parser, $showAll, $threshold, &$rows, &$changed, &$bandChanged,
                &$classChanged, &$missingFile, &$noPath, &$errors, &$unchanged, &$scanned
            ): void {
                foreach ($tests as $test) {
                    $scanned++;
                    $path = $test->csv_path;

                    if (blank($path)) {
                        $noPath++;

                        continue;
                    }
                    if (! Storage::disk('local')->exists($path)) {
                        $missingFile++;

                        continue;
                    }

                    try {
                        $parsed = $parser->parse(Storage::disk('local')->path($path));
                    } catch (Throwable $e) {
                        $errors++;
                        $this->warn("Test {$test->id}: parse failed ({$e->getMessage()}) — skipped.");

                        continue;
                    }

                    $old = (float) ($test->diversity_score ?? 0);
                    $new = (float) $parsed['diversity_score'];
                    $delta = round($new - $old, 2);

                    // Bands + classification recomputed with the NEW diversity, holding
                    // richness/dysbiosis at their stored values (this fix only moves
                    // diversity).
                    $richness = (float) ($test->species_richness ?? 0);
                    $dysbiosis = (float) ($test->dysbiosis_score ?? 0);

                    $oldBand = ReportContent::diversityBand($old)['label'];
                    $newBand = ReportContent::diversityBand($new)['label'];
                    $oldClass = (string) ($test->microbiome_classification ?? '');
                    $newClass = ReportContent::classify($new, $richness, $dysbiosis);

                    $valueMoved = abs($delta) >= $threshold;
                    $bandMoved = $oldBand !== $newBand;
                    $classMoved = $oldClass !== '' && $oldClass !== $newClass;

                    if (! $valueMoved && ! $bandMoved && ! $classMoved) {
                        $unchanged++;
                        if (! $showAll) {
                            continue;
                        }
                    } else {
                        $changed++;
                        $bandMoved and $bandChanged++;
                        $classMoved and $classChanged++;
                    }

                    $rows[] = [
                        $test->id,
                        $test->reports->pluck('id')->implode(', ') ?: '—',
                        $test->sample_id ?: '—',
                        number_format($old, 2),
                        number_format($new, 2),
                        sprintf('%+.2f', $delta),
                        $bandMoved ? "{$oldBand} → {$newBand}" : $newBand,
                        $classMoved ? "{$oldClass} → {$newClass}" : ($oldClass ?: $newClass),
                    ];
                }
            });

        if ($rows !== []) {
            // Biggest movers first — those are the ones to eyeball.
            usort($rows, fn (array $a, array $b): int => abs((float) $b[5]) <=> abs((float) $a[5]));
            $this->table(
                ['Test', 'Report(s)', 'Sample', 'Stored', 'Recomputed', 'Δ', 'Diversity band', 'Classification'],
                $rows,
            );
        } else {
            $this->info('No differences found.');
        }

        $this->newLine();
        $this->info("Scanned {$scanned} test(s) with a csv_path.");
        $this->line("  changed value: {$changed}   (band changes: {$bandChanged}, classification changes: {$classChanged})");
        $this->line("  unchanged: {$unchanged}   missing CSV file: {$missingFile}   no csv_path: {$noPath}   parse errors: {$errors}");
        $this->newLine();
        $this->comment('READ-ONLY: nothing was written. Review the list above before deciding to re-store any values.');

        return self::SUCCESS;
    }
}
