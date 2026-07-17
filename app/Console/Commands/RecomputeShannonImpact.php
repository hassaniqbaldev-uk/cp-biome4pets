<?php

namespace App\Console\Commands;

use App\Models\Test;
use App\Services\CsvParserService;
use App\Support\ReportContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Impact assessment + repair for the Shannon diversity fix (report-4050 bug).
 *
 * The old parser underestimated Shannon whenever part of a sample's abundance sat in
 * rows that only classify to genus/family (see CsvParserService::shannon). Stored
 * reports therefore may carry a too-low diversity score — and because
 * ReportContent::classify() gates on diversity < 1.9 and the display bands cut at
 * 1.9 / 2.5, a too-low score can also mean a WORSE diversity band and a WORSE
 * microbiome classification shown to a customer.
 *
 * The fixed parser only helps NEW reports; existing rows keep their old value until
 * re-stored. This command recomputes Shannon for every test from its RETAINED CSV:
 *   - WITHOUT --force (the default): strictly READ-ONLY. Reports what WOULD change
 *     and writes nothing.
 *   - WITH --force: re-stores the corrected values (see "what --force writes" below).
 * Either way: a test whose CSV file is missing (very old records) is skipped and
 * counted, a parse failure is caught and counted, and the run never aborts.
 *
 * ── WHAT --force WRITES (and why) ────────────────────────────────────────────────
 * diversity_score is stored in TWO places, and microbiome_classification is a STORED
 * column — not derived — so a naive "just fix diversity" would leave a corrected
 * score sitting next to a stale, contradictory classification. --force therefore
 * writes exactly four things and nothing else:
 *   1. tests.diversity_score          — the AUTHORITATIVE column. Everything reads
 *                                       this via the Report→Test proxy.
 *   2. tests.microbiome_classification — STORED (string column), so it MUST be
 *                                       recomputed. Derived as
 *                                       classify(NEW diversity, STORED richness,
 *                                       STORED dysbiosis) so the stored triplet stays
 *                                       self-consistent and --force writes precisely
 *                                       what the read-only preview displayed.
 *   3. csv_data['diversity_score']     — the parse-blob copy, kept in sync with (1).
 *   4. csv_data['microbiome_classification'] — the blob copy, kept in sync with (2).
 * Every other csv_data key (phylum_totals, top_taxa, insight_taxa, species_richness,
 * dysbiosis_score, …) and every other column is left untouched, via saveQuietly() so
 * no model events fire.
 *
 * The diversity BAND is NOT stored anywhere — ReportContent::diversityBand() is
 * computed live at display time from the score — so it corrects itself automatically
 * once (1) is written. Nothing to do for it.
 *
 * IDEMPOTENT: re-running --force recomputes from the same CSV and re-stores the same
 * values; a second run reports everything as unchanged.
 */
class RecomputeShannonImpact extends Command
{
    protected $signature = 'reports:recompute-shannon-impact
        {--force : Re-store the corrected diversity + classification. Without this the command is read-only.}
        {--all : List every test, not just the ones whose value changes}
        {--threshold=0.005 : Minimum absolute change to count as changed}';

    protected $description = 'Recompute Shannon from retained CSVs; reports what would change (read-only by default), or re-stores the corrected diversity + classification with --force.';

    public function handle(CsvParserService $parser): int
    {
        $force = (bool) $this->option('force');
        $showAll = (bool) $this->option('all');
        $threshold = (float) $this->option('threshold');

        $rows = [];
        $changed = $bandChanged = $classChanged = $updated = 0;
        $missingFile = $noPath = $errors = $unchanged = $scanned = 0;

        Test::query()
            ->withTrashed()
            ->whereNotNull('csv_path')
            ->with('reports')
            ->chunkById(100, function ($tests) use (
                $parser, $force, $showAll, $threshold, &$rows, &$changed, &$bandChanged,
                &$classChanged, &$updated, &$missingFile, &$noPath, &$errors,
                &$unchanged, &$scanned
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

                    // Band + classification recomputed with the NEW diversity, holding
                    // richness/dysbiosis at their STORED values (this fix only moves
                    // diversity). Using the stored pair — rather than the freshly
                    // parsed one — keeps the stored triplet self-consistent and makes
                    // --force write exactly what the read-only preview showed.
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

                        if ($force) {
                            // Write ONLY the diversity score + its derived, STORED
                            // classification. csv_data is merged key-wise so every
                            // other key (phylum_totals, top_taxa, insight_taxa,
                            // species_richness, dysbiosis_score, …) survives intact.
                            // saveQuietly() → no model events, nothing else touched.
                            $csvData = $test->csv_data ?? [];
                            $csvData['diversity_score'] = $new;
                            $csvData['microbiome_classification'] = $newClass;

                            $test->forceFill([
                                'diversity_score' => $new,                 // authoritative column
                                'microbiome_classification' => $newClass,  // stored → must be recomputed
                                'csv_data' => $csvData,                    // blob copies kept in sync
                            ])->saveQuietly();

                            $updated++;
                        }
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

        $skipped = $missingFile + $noPath + $errors;

        $this->newLine();
        $this->info("Scanned {$scanned} test(s) with a csv_path.");

        if ($force) {
            $this->line("  UPDATED: {$updated} test(s) re-stored (diversity + classification).");
            $this->line("    of which band changes applied:           {$bandChanged}");
            $this->line("    of which classification changes applied: {$classChanged}");
            $this->line("  unchanged (already correct): {$unchanged}");
            $this->line("  skipped: {$skipped}   (missing CSV file: {$missingFile}, no csv_path: {$noPath}, parse errors: {$errors})");
            $this->newLine();
            $this->info('--force: corrected values written. The diversity BAND is derived live at display time, so it follows automatically. Re-run to verify everything now reports as unchanged.');
        } else {
            $this->line("  would change: {$changed}   (band changes: {$bandChanged}, classification changes: {$classChanged})");
            $this->line("  unchanged: {$unchanged}");
            $this->line("  skipped: {$skipped}   (missing CSV file: {$missingFile}, no csv_path: {$noPath}, parse errors: {$errors})");
            $this->newLine();
            $this->comment('READ-ONLY: nothing was written. Re-run with --force to re-store the corrected values.');
        }

        return self::SUCCESS;
    }
}
