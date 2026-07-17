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
        // Shannon inputs for the INCLUDED species rows, kept as RAW per-row values
        // (not proportions) so they can be renormalised over the included set after
        // the loop — see shannon(). Both units are collected: exact counts, plus the
        // lab's pre-rounded percentages as a whole-sample fallback.
        $speciesCounts = [];
        $speciesPcts = [];
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

            // DIVERSITY taxa set: species-resolved rows carrying abundance. Rows that
            // only classify to genus/family (blank Species) are deliberately EXCLUDED
            // — the lab's Shannon is "over classified species only". Their abundance
            // is instead accounted for by renormalising in shannon() below.
            $species = $data['Species'] ?? '';
            if ($species !== '' && $pctHits > 0) {
                $speciesPcts[] = $pctHits;
                $speciesCounts[] = (float) ($data['num_hits'] ?? 0);
            }

            // RICHNESS taxa set: non-empty and not "Unclassified".
            // NB: this is NOT the same set as the diversity one above — richness also
            // drops rows whose Species is literally "Unclassified" (which diversity
            // keeps) and does not require abundance > 0. Flagged in the audit; left
            // as-is deliberately because aligning it would change a second
            // customer-facing figure (and the richness-gated classification), so it
            // needs sign-off first. See ReportContent::classify() / richnessBand().
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

        $diversityScore = self::shannon($speciesCounts, $speciesPcts);

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
        $insightTaxa = $this->buildInsightTaxa($genusPctTotals);

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
            // Stage 1 of the deterministic health-insights rework: the SPECIFIC
            // genus percentages the forthcoming rules need, captured regardless of
            // top-20 ranking (Escherichia/Shigella especially is usually too low to
            // rank). Canonical key => summed %, with an entry for EVERY needed genus
            // even when absent (stored 0) so the rule layer can treat absent as 0.
            // Also rides inside csv_data (JSON) — no migration. NO display/rule use
            // yet; this only makes the data reliably available.
            'insight_taxa' => $insightTaxa,
        ];
    }

    /**
     * The Shannon diversity index over the INCLUDED species rows:
     *
     *     H = -Σ p_i · ln(p_i),   p_i = value_i / Σ(value over included rows)
     *
     * The proportions are RENORMALISED over the included set so Σp = 1.0, which
     * Shannon requires. This is the fix for the report-4050 bug: the previous code
     * used a hardcoded p = %_hits / 100, so on a sample where some abundance sits in
     * rows that only classify to genus/family (blank Species — correctly excluded
     * from the taxa set), the remaining proportions summed to LESS than 1.0 and H was
     * systematically UNDERESTIMATED. On 4050 the species rows carry 83.065% of the
     * abundance: /100 gave 2.37 (the stored, wrong value); renormalising over the
     * included subtotal gives 2.67 (the lab's correct value). On a fully-resolved
     * sample the subtotal is ~100, so renormalising changes nothing.
     *
     * UNITS: prefers the exact raw counts (num_hits); the lab's %_hits are pre-rounded
     * and lose precision. Falls back to %_hits for the WHOLE sample when any included
     * row lacks a usable count — the two units are never mixed within one
     * normalisation, which would be meaningless.
     *
     * @param  array<int,float>  $counts  per-row num_hits for the included rows
     * @param  array<int,float>  $pcts  per-row %_hits for the same rows, same order
     */
    private static function shannon(array $counts, array $pcts): float
    {
        // Use counts only when EVERY included row has a usable (>0) count.
        $countsUsable = $counts !== [] && count(array_filter($counts, fn (float $c): bool => $c > 0)) === count($counts);
        $values = $countsUsable ? $counts : $pcts;

        $total = array_sum($values);
        if ($total <= 0) {
            return 0.0;
        }

        $h = 0.0;
        foreach ($values as $value) {
            if ($value <= 0) {
                continue;
            }
            $p = $value / $total;   // renormalised — Σp = 1.0
            $h -= $p * log($p);
        }

        return round($h, 2);
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
     * The specific genera the Stage-1 health-insights rework must have reliably
     * available per report, as: canonical storage key => the lowercase alias
     * TOKENS that identify the genus in a raw CSV Genus cell. A raw genus matches
     * when any of its normalised tokens (see normaliseTokens) is one of these.
     *
     * Escherichia/Shigella is a single combined SILVA-style genus that appears as
     * "Escherichia-Shigella", "Escherichia_Shigella", "Escherichia/Shigella" etc;
     * matching on EITHER token captures it whatever the separator, and also folds a
     * lab that happens to split it into two rows back into one canonical total.
     *
     * The canonical keys ('blautia', 'escherichia_shigella') are the stable storage
     * keys in csv_data['insight_taxa']; the read side (ReportContent) maps them to
     * display names. Keep the two in sync when adding a genus.
     *
     * @var array<string,array<int,string>>
     */
    public const INSIGHT_GENERA = [
        'blautia' => ['blautia'],
        'escherichia_shigella' => ['escherichia', 'shigella'],
    ];

    /**
     * Build the insight_taxa map: for each needed genus (INSIGHT_GENERA), the sum
     * of the %_hits of every raw genus whose normalised tokens match one of its
     * aliases. Seeds every canonical key at 0 so an absent genus is stored as 0
     * (not a missing key) — the later rule layer can treat 0 as "absent". Rounded
     * last so the sum is accumulated at full precision.
     *
     * @param  array<string,float>  $genusPctTotals  raw genus name => summed %
     * @return array<string,float>  canonical genus key => summed %
     */
    private function buildInsightTaxa(array $genusPctTotals): array
    {
        $insight = array_fill_keys(array_keys(self::INSIGHT_GENERA), 0.0);

        foreach ($genusPctTotals as $rawGenus => $pct) {
            $tokens = self::normaliseTokens((string) $rawGenus);
            if ($tokens === []) {
                continue;
            }

            foreach (self::INSIGHT_GENERA as $canonical => $aliases) {
                if (array_intersect($tokens, $aliases) !== []) {
                    $insight[$canonical] += $pct;
                }
            }
        }

        return array_map(fn (float $v): float => round($v, 2), $insight);
    }

    /**
     * Normalise a raw taxon token into lowercase alphanumeric WORD TOKENS: drop any
     * trailing accession in parentheses, lowercase, and split on every run of
     * non-alphanumeric characters (so "/", "-", "_" and whitespace all separate).
     * "Escherichia-Shigella" -> ['escherichia','shigella']; "Blautia" -> ['blautia'].
     * Empty input (or an all-separator string) yields [].
     *
     * @return array<int,string>
     */
    private static function normaliseTokens(string $raw): array
    {
        $name = preg_replace('/\([^)]*\)\s*$/', '', $raw) ?? $raw;  // strip accession
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;  // separators → space
        $name = trim($name);

        return $name === '' ? [] : explode(' ', $name);
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
