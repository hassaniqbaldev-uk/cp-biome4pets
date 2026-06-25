<?php

namespace App\Services;

use App\Models\ProductRule;
use App\Support\ReportContent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CsvParserService
{
    /**
     * Hard ceiling on data rows parsed from a single CSV. A genuine lab export is
     * one row per detected species — a few thousand at most — so 50,000 is ample
     * headroom while bounding CPU: without it a crafted (but still <10MB) file of
     * millions of tiny rows could spin the parse loop in an interactive admin
     * request. Exceeding the cap is treated as a malformed/hostile file.
     */
    public const MAX_DATA_ROWS = 50000;

    public function parse(string $filePath, ?int $maxRows = null): array
    {
        $maxRows ??= self::MAX_DATA_ROWS;

        if (! file_exists($filePath)) {
            throw new RuntimeException("CSV file not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file: {$filePath}");
        }

        $header = fgetcsv($handle);

        if (! is_array($header) || empty($header)) {
            fclose($handle);
            throw new RuntimeException("CSV file has no header row: {$filePath}");
        }

        $header = array_map('trim', $header);
        $headerCount = count($header);

        $phylumTotals = [];
        $speciesProportions = [];
        $speciesRichness = 0;
        $rowCount = 0;

        // Stage 1 retention: the ONLY specific-bacteria data the rest of the
        // pipeline may use. We accumulate per-genus rollups (sum of a genus's
        // rows) and per-species percentages by RAW name, then clean + rank +
        // cap after the loop. Additive only — the phylum/diversity/richness/
        // dysbiosis logic below is untouched.
        $genusPctTotals = [];
        $speciesPctTotals = [];

        while (($row = fgetcsv($handle)) !== false) {
            // Bound the work regardless of how the rows are shaped — count every
            // physical data row read, then reject once the cap is passed.
            if (++$rowCount > $maxRows) {
                fclose($handle);
                throw new RuntimeException("CSV exceeds the maximum of {$maxRows} rows.");
            }

            if (! is_array($row)) {
                continue;
            }

            // Skip rows that don't match header column count
            if (count($row) !== $headerCount) {
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));

            if ($data === false) {
                continue;
            }

            $phylum = $data['Phylum'] ?? '';
            $pctHits = (float) ($data['%_hits'] ?? 0);

            if ($phylum !== '' && $phylum !== 'Unclassified') {
                $phylumTotals[$phylum] = ($phylumTotals[$phylum] ?? 0) + $pctHits;
            }

            $species = $data['Species'] ?? '';
            if ($species !== '' && $pctHits > 0) {
                $speciesProportions[] = $pctHits / 100;
            }

            // Count species richness: non-empty, non-null, not containing "Unclassified"
            if ($species !== '' && stripos($species, 'Unclassified') === false) {
                $speciesRichness++;
            }

            // Retain genus + species detail for real names only (non-empty, not
            // "Unclassified"), keyed by raw name so duplicates/variants merge.
            $genus = $data['Genus'] ?? '';
            if ($genus !== '' && stripos($genus, 'Unclassified') === false && $pctHits > 0) {
                $genusPctTotals[$genus] = ($genusPctTotals[$genus] ?? 0) + $pctHits;
            }
            if ($species !== '' && stripos($species, 'Unclassified') === false && $pctHits > 0) {
                $speciesPctTotals[$species] = ($speciesPctTotals[$species] ?? 0) + $pctHits;
            }
        }

        fclose($handle);

        $phylumTotals = array_map(fn ($v) => round($v, 2), $phylumTotals);

        $diversityScore = 0.0;
        if (count($speciesProportions) > 0) {
            foreach ($speciesProportions as $p) {
                if ($p > 0) {
                    $diversityScore -= $p * log($p);
                }
            }
        }

        $diversityScore = round($diversityScore, 2);

        // Calculate dysbiosis score (Firmicutes / Bacteroidetes ratio)
        $firmicutes = $phylumTotals['Firmicutes'] ?? 0;
        $bacteroidetes = $phylumTotals['Bacteroidetes'] ?? 0;
        $dysbiosisScore = $bacteroidetes > 0 ? round($firmicutes / $bacteroidetes, 2) : 0;

        // Determine microbiome classification. The thresholds live in ONE place
        // (ReportContent — the same source the report bands render from) so the
        // computed badge can never drift from the printed Diversity/Richness/
        // Dysbiosis bands.
        $microbiomeClassification = ReportContent::classify(
            $diversityScore,
            $speciesRichness,
            $dysbiosisScore,
        );

        $topTaxa = $this->buildTopTaxa($genusPctTotals, $speciesPctTotals);

        return [
            'phylum_totals' => $phylumTotals,
            'diversity_score' => $diversityScore,
            'species_richness' => $speciesRichness,
            'dysbiosis_score' => $dysbiosisScore,
            'microbiome_classification' => $microbiomeClassification,
            // The pet's specific bacteria (top ~20 by %), genus rollups + notable
            // species, each tagged with its rank. Rides inside csv_data (JSON) — no
            // migration. Absent on pre-existing reports; readers default to [].
            'top_taxa' => $topTaxa,
        ];
    }

    /**
     * Number of retained taxa (genus rollups + species combined) kept in
     * top_taxa. The prompt features only the notable handful; storage keeps a
     * slightly deeper list for context/auditing without bloating the JSON.
     */
    public const TOP_TAXA_LIMIT = 20;

    /**
     * Build the ranked top_taxa list from the accumulated raw genus/species
     * percentage maps: clean each raw name to a readable label, tag its rank,
     * merge genus + species into one list, sort by % desc, cap, and round.
     *
     * @param  array<string,float>  $genusPctTotals  raw genus name => summed %
     * @param  array<string,float>  $speciesPctTotals  raw species name => summed %
     * @return array<int,array{name:string,rank:string,pct:float}>
     */
    private function buildTopTaxa(array $genusPctTotals, array $speciesPctTotals): array
    {
        $taxa = [];

        foreach ($genusPctTotals as $raw => $pct) {
            $name = self::cleanTaxonName($raw);
            if ($name !== '') {
                $taxa[] = ['name' => $name, 'rank' => 'genus', 'pct' => $pct];
            }
        }
        foreach ($speciesPctTotals as $raw => $pct) {
            $name = self::cleanTaxonName($raw);
            if ($name !== '') {
                $taxa[] = ['name' => $name, 'rank' => 'species', 'pct' => $pct];
            }
        }

        // Highest % first; cap; round last so the cap is decided on full precision.
        usort($taxa, fn (array $a, array $b): int => $b['pct'] <=> $a['pct']);
        $taxa = array_slice($taxa, 0, self::TOP_TAXA_LIMIT);

        return array_map(
            fn (array $t): array => ['name' => $t['name'], 'rank' => $t['rank'], 'pct' => round($t['pct'], 2)],
            $taxa,
        );
    }

    /**
     * Turn a raw lab taxon token into a human-readable name: drop the trailing
     * accession in parentheses (e.g. "(NR_117734.1)"), turn underscores into
     * spaces, and collapse whitespace. "Fusobacterium_perfoetens(M58684)" ->
     * "Fusobacterium perfoetens"; "Clostridium_sensu_stricto" -> "Clostridium
     * sensu stricto".
     */
    private static function cleanTaxonName(string $raw): string
    {
        $name = preg_replace('/\([^)]*\)\s*$/', '', $raw) ?? $raw;  // strip accession
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;        // collapse spaces

        return trim($name);
    }

    /**
     * Evaluate configurable product rules from the product_rules table against
     * the report's metrics, returning the set of fired trigger names.
     *
     * Same input/output shape as before. If the table is empty or unreadable,
     * falls back to the original hardcoded rules so report generation can
     * never break.
     */
    public function evaluateProductRules(array $phylumTotals, float $diversityScore): array
    {
        // Metrics available to rules: phylum percentages plus the diversity score.
        $metrics = $phylumTotals;
        $metrics['diversity_score'] = $diversityScore;

        try {
            $rules = ProductRule::query()->where('is_active', true)->orderBy('id')->get();
        } catch (\Throwable $e) {
            Log::warning('product_rules unreadable, falling back to hardcoded rules.', ['error' => $e->getMessage()]);

            return $this->evaluateHardcodedProductRules($phylumTotals, $diversityScore);
        }

        if ($rules->isEmpty()) {
            return $this->evaluateHardcodedProductRules($phylumTotals, $diversityScore);
        }

        // Use an ordered set so multiple active rows sharing a trigger_name OR
        // together and the trigger is only reported once.
        $triggered = [];

        foreach ($rules as $rule) {
            if (! array_key_exists($rule->metric, $metrics)) {
                // Warn but don't crash on a rule referencing an unknown metric.
                Log::warning('Product rule references a metric not present in report data; skipping.', [
                    'trigger_name' => $rule->trigger_name,
                    'metric' => $rule->metric,
                ]);

                continue;
            }

            if ($rule->matches((float) $metrics[$rule->metric])) {
                $triggered[$rule->trigger_name] = true;
            }
        }

        return array_keys($triggered);
    }

    /**
     * Hardcoded fallback rules used when product_rules is empty/unreadable.
     * Thresholds mirror the canonical ProductRuleSeeder set so a fresh deploy
     * isn't stale. Returns a flat list of distinct trigger-name strings.
     */
    private function evaluateHardcodedProductRules(array $phylumTotals, float $diversityScore): array
    {
        $triggered = [];

        $bacteroidetes = $phylumTotals['Bacteroidetes'] ?? 0;
        if ($bacteroidetes > 30 || $bacteroidetes < 10) {
            $triggered[] = 'AMR';
        }

        $firmicutes = $phylumTotals['Firmicutes'] ?? 0;
        if ($firmicutes < 18) {
            $triggered[] = 'Prebiotic';
        }

        if ($bacteroidetes > 30) {
            $triggered[] = 'Antimicrobic';
        }

        if ($diversityScore < 1.6) {
            $triggered[] = 'FMT';
        }

        return $triggered;
    }
}
