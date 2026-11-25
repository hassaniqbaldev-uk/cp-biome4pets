<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Support\RetestPlanRules;
use App\Support\TaxonReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * READ-ONLY real-data sanity pass (step 2b / audit PART B). For every existing report it
 * evaluates which retest THRESHOLD rules would fire if the report were treated as a
 * retest — to sanity-check the thresholds on real dogs and spot over-firing or values
 * sitting on a boundary. It writes NOTHING: only SELECTs, no save/update/delete/migrate.
 *
 * Primary source is TaxonReader over the report's stored lab CSV (all threshold rules).
 * When the CSV FILE is not present on this machine (a DB copy without its file storage),
 * it falls back to the test's stored summary for the two rules that are fully derivable
 * without the raw rows — Spirochaetes (phylum_data) and Escherichia/Shigella
 * (insight_taxa) — and marks the other rules "n/a (CSV absent)". The FMT delta rule is
 * intentionally skipped (it needs a prior test's comparison, out of scope here).
 */
class RetestThresholdScan extends Command
{
    protected $signature = 'retest:threshold-scan {--limit=0 : Max reports to scan (0 = all)} {--only-fired : Only print reports where at least one rule fires}';

    protected $description = 'READ-ONLY: report which retest threshold rules would fire for existing reports (TaxonReader over the stored CSV; stored-summary fallback when the CSV file is absent). Writes nothing.';

    public function handle(): int
    {
        $db = (string) DB::connection()->getDatabaseName();

        // Hard refusal: this scan is for the local copy, never a live/production database.
        if (preg_match('/live|prod/i', $db)) {
            $this->error("Refusing to run against '{$db}'. This read-only scan is for biome4pets_copy only.");

            return self::FAILURE;
        }

        $this->info("READ-ONLY retest threshold scan over database: {$db}");
        $this->line('No writes are performed — SELECT only.');
        $this->newLine();

        // Only the declarative THRESHOLD rules (the FMT delta rule is skipped here).
        $thresholdRules = array_values(array_filter(
            RetestPlanRules::RETEST_RULES,
            fn (array $r): bool => ($r['type'] ?? '') === 'threshold',
        ));
        // Priority lookup so the "resulting plan" is the highest-priority fired rule.
        usort($thresholdRules, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $limit = (int) $this->option('limit');
        $onlyFired = (bool) $this->option('only-fired');

        $perRule = array_fill_keys(array_column($thresholdRules, 'key'), 0);
        $rows = [];
        $scanned = 0;
        $csvSource = 0;
        $summarySource = 0;
        $noData = 0;
        $anyFired = 0;

        $query = Report::query()->with(['pet', 'test'])->orderBy('id');

        foreach ($query->cursor() as $report) {
            if ($limit > 0 && $scanned >= $limit) {
                break;
            }
            $scanned++;

            $test = $report->test;
            $reader = $this->readerFor($test);        // ?TaxonReader (CSV present) or null
            $source = $reader !== null ? 'csv' : ($test !== null ? 'summary' : 'none');

            if ($source === 'csv') {
                $csvSource++;
            } elseif ($source === 'summary') {
                $summarySource++;
            } else {
                $noData++;
            }

            $crossing = [];   // "rank:name=val" strings for taxa over threshold
            $fired = [];      // fired rule keys, in priority order

            foreach ($thresholdRules as $rule) {
                $ruleCrossing = [];
                $evaluable = false;

                foreach ($rule['taxa'] as [$rank, $name]) {
                    $pct = $this->taxonPercent($reader, $test, (string) $rank, (string) $name);
                    if ($pct === null) {
                        continue; // this taxon can't be evaluated from the available data
                    }
                    $evaluable = true;
                    if ($pct > (float) $rule['threshold']) {
                        $ruleCrossing[] = "{$rank}:{$name}=".rtrim(rtrim(number_format($pct, 2), '0'), '.')." > {$rule['threshold']}";
                    }
                }

                if ($evaluable && $ruleCrossing !== []) {
                    $fired[] = $rule['key'];
                    $perRule[$rule['key']]++;
                    $crossing = array_merge($crossing, $ruleCrossing);
                }
            }

            if ($fired !== []) {
                $anyFired++;
            } elseif ($onlyFired) {
                continue;
            }

            // Resulting plan = the highest-priority fired rule's plan (rules are sorted).
            $winnerPlan = '';
            foreach ($thresholdRules as $rule) {
                if (in_array($rule['key'], $fired, true)) {
                    $winnerPlan = $rule['plan_key'];
                    break;
                }
            }

            $rows[] = [
                $report->getKey(),
                $report->pet?->name ?? '—',
                $source === 'csv' ? 'CSV' : ($source === 'summary' ? 'summary*' : 'no data'),
                $crossing === [] ? '—' : implode("\n", $crossing),
                $fired === [] ? '—' : implode(', ', $fired),
                $winnerPlan ?: '—',
            ];
        }

        $this->table(
            ['Report', 'Pet', 'Source', 'Taxa crossing threshold', 'Rule(s) fired', 'Resulting plan'],
            $rows,
        );

        $this->newLine();
        $this->info('Per-rule fire counts (threshold rules):');
        $summaryRows = [];
        foreach ($thresholdRules as $rule) {
            $summaryRows[] = [$rule['key'], $rule['plan_key'], $rule['threshold'], $perRule[$rule['key']]];
        }
        $this->table(['Rule', 'Plan', 'Threshold (>)', 'Reports firing'], $summaryRows);

        $this->newLine();
        $this->line("Scanned: {$scanned} reports  |  evaluated from CSV: {$csvSource}  |  from stored summary*: {$summarySource}  |  no test data: {$noData}  |  at least one rule fired: {$anyFired}");
        if ($summarySource > 0) {
            $this->warn('* summary = CSV file absent on this machine; only Spirochaetes (phylum_data) and Escherichia/Shigella (insight_taxa) were evaluable — the other threshold rules are reported as not-fired for those reports because their taxa need the raw CSV.');
        }

        return self::SUCCESS;
    }

    /** A TaxonReader over the test's CSV, or null when the file is not present. */
    private function readerFor(?object $test): ?TaxonReader
    {
        $rel = $test?->csv_path;
        if (! filled($rel)) {
            return null;
        }
        $disk = Storage::disk('local');

        return $disk->exists($rel) ? TaxonReader::fromCsv($disk->path($rel)) : null;
    }

    /**
     * The percentage for a taxon, or null when it can't be evaluated from available data.
     * CSV present → TaxonReader (any rank). CSV absent → stored summary for the two
     * fully-derivable taxa only (phylum via phylum_data, Escherichia/Shigella via
     * insight_taxa); everything else returns null (not evaluable).
     */
    private function taxonPercent(?TaxonReader $reader, ?object $test, string $rank, string $name): ?float
    {
        if ($reader !== null) {
            return $reader->taxonPercent($rank, $name);
        }

        if ($test === null) {
            return null;
        }

        if ($rank === 'phylum') {
            $phyla = is_array($test->phylum_data) ? $test->phylum_data : [];

            return (float) ($phyla[$name] ?? 0.0);
        }

        if ($rank === 'genus' && strcasecmp($name, 'Escherichia/Shigella') === 0) {
            $insight = is_array($test->csv_data['insight_taxa'] ?? null) ? $test->csv_data['insight_taxa'] : [];

            return (float) ($insight['escherichia_shigella'] ?? 0.0);
        }

        return null; // needs the raw CSV
    }
}
