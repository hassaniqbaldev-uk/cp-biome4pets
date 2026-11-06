<?php

namespace App\Support;

use App\Services\CsvParserService;

/**
 * Read-only microbiome RETEST COMPARISON ENGINE (first slice of the retest feature).
 *
 * Compares an ORIGINAL test to a RETEST for the same pet and returns:
 *   - overview metrics for both tests — diversity (Shannon), species richness and the
 *     Firmicutes/Bacteroidetes ratio — taken VERBATIM from the portal's
 *     CsvParserService, never recomputed; and
 *   - a per-taxon comparison at phylum, class, genus and species level, each entry
 *     carrying original_pct, retest_pct, delta (retest − original) and a direction.
 *
 * Everything the portal already computes (the species-rows Shannon, richness, phylum
 * totals, the F/B ratio) is REUSED through CsvParserService::parse(). parse() exposes
 * only phylum_totals plus a top-20-capped top_taxa, so the ONE new calculation here is a
 * small per-level %_hits roll-up (rollupsByRank) that reads the CSV rows directly to give
 * full class / genus / species coverage. No new parser, no new Shannon.
 *
 * Pure and side-effect free: it reads the two CSVs and returns an array. No DB, no model,
 * no writes.
 */
class RetestComparison
{
    /** |delta| at or below this (percentage points) counts as "flat". */
    public const FLAT_EPSILON = 0.05;

    /**
     * The ranks the full roll-up covers, in report order, mapped to their CSV column.
     * Phylum is intentionally absent: its comparison reuses the portal's phylum_totals.
     */
    private const RANK_COLUMNS = [
        'class' => 'Class',
        'genus' => 'Genus',
        'species' => 'Species',
    ];

    /**
     * Compare two tests given their lab-summary CSV paths.
     *
     * The engine parses each test itself because the per-level roll-up needs the raw
     * rows, which parse() does not expose (it returns only phylum_totals + a capped
     * top_taxa). The overview + phylum figures still come straight from parse().
     *
     * @return array{
     *   overview: array{original: array<string,float|int|null>, retest: array<string,float|int|null>},
     *   taxa: array<string, array<int, array{name:string, original_pct:float, retest_pct:float, delta:float, direction:string}>>
     * }
     */
    public static function build(string $originalCsvPath, string $retestCsvPath): array
    {
        $parser = new CsvParserService;
        $originalParse = $parser->parse($originalCsvPath);
        $retestParse = $parser->parse($retestCsvPath);

        // Phylum comparison reuses the portal's own phylum_totals (not recomputed).
        $taxa = [
            'phylum' => self::compareLevel(
                $originalParse['phylum_totals'] ?? [],
                $retestParse['phylum_totals'] ?? [],
            ),
        ];

        // Class / genus / species need fuller coverage than the capped top_taxa can
        // give (e.g. a near-absent genus at 0.003%), so roll each level up directly.
        $originalRoll = self::rollupsByRank($originalCsvPath);
        $retestRoll = self::rollupsByRank($retestCsvPath);
        foreach (array_keys(self::RANK_COLUMNS) as $rank) {
            $taxa[$rank] = self::compareLevel($originalRoll[$rank] ?? [], $retestRoll[$rank] ?? []);
        }

        return [
            'overview' => [
                'original' => self::overview($originalParse),
                'retest' => self::overview($retestParse),
            ],
            'taxa' => $taxa,
        ];
    }

    /** The three overview metrics, taken verbatim from a parse() result. */
    private static function overview(array $parse): array
    {
        return [
            'diversity' => $parse['diversity_score'] ?? null,
            'species_richness' => $parse['species_richness'] ?? null,
            // dysbiosis_score IS the Firmicutes/Bacteroidetes ratio (CsvParserService).
            'firmicutes_bacteroidetes_ratio' => $parse['dysbiosis_score'] ?? null,
        ];
    }

    /**
     * Compare one taxonomic level: union the taxa present in either test and, for each,
     * emit original_pct, retest_pct, delta (retest − original) and direction. Sorted by
     * retest_pct desc, then name asc.
     *
     * @param  array<string,float>  $original  name => pct
     * @param  array<string,float>  $retest    name => pct
     * @return array<int, array{name:string, original_pct:float, retest_pct:float, delta:float, direction:string}>
     */
    private static function compareLevel(array $original, array $retest): array
    {
        $out = [];
        foreach (array_keys($original + $retest) as $name) {
            $o = round((float) ($original[$name] ?? 0), 3);
            $r = round((float) ($retest[$name] ?? 0), 3);
            $delta = round($r - $o, 3);
            $out[] = [
                'name' => (string) $name,
                'original_pct' => $o,
                'retest_pct' => $r,
                'delta' => $delta,
                'direction' => self::direction($delta),
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['retest_pct'] <=> $a['retest_pct'] ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /** up / down / flat — flat when the move is within FLAT_EPSILON. */
    private static function direction(float $delta): string
    {
        if (abs($delta) <= self::FLAT_EPSILON) {
            return 'flat';
        }

        return $delta > 0 ? 'up' : 'down';
    }

    /**
     * The ONE new calculation: sum %_hits per taxon for the class/genus/species ranks,
     * straight from the CSV rows. Mirrors CsvParserService's inclusion rule (name
     * non-empty, not "Unclassified", %_hits > 0) and its accession/underscore name
     * cleaning, so taxa line up with the portal's top_taxa labels.
     *
     * @return array<string, array<string,float>>  rank => [cleanName => summed pct]
     */
    private static function rollupsByRank(string $csvPath): array
    {
        $roll = array_fill_keys(array_keys(self::RANK_COLUMNS), []);

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return $roll;
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return $roll;
        }
        $header = array_map('trim', $header);
        $columnCount = count($header);

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row) || count($row) !== $columnCount) {
                continue;
            }
            $data = array_combine($header, array_map('trim', $row));
            if ($data === false) {
                continue;
            }

            $pct = (float) ($data['%_hits'] ?? 0);
            if ($pct <= 0) {
                continue;
            }

            foreach (self::RANK_COLUMNS as $rank => $column) {
                $raw = $data[$column] ?? '';
                if ($raw === '' || stripos($raw, 'Unclassified') !== false) {
                    continue;
                }
                $name = self::cleanName($raw);
                if ($name === '') {
                    continue;
                }
                $roll[$rank][$name] = ($roll[$rank][$name] ?? 0) + $pct;
            }
        }
        fclose($handle);

        return $roll;
    }

    /**
     * Readable taxon label: drop a trailing accession in parentheses, underscores to
     * spaces, collapse whitespace — the same shape as CsvParserService::cleanTaxonName,
     * which is private, so it is mirrored here rather than reused. Keep the two in sync.
     */
    private static function cleanName(string $raw): string
    {
        $name = preg_replace('/\([^)]*\)\s*$/', '', $raw) ?? $raw;
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    /*
    |---------------------------------------------------------------------------
    | Key Changes — suggested biggest-mover default (deterministic, snapshot-only)
    |---------------------------------------------------------------------------
    | Turns the frozen genus comparison into a proposed "Key Changes" list the
    | team will later edit. No AI, no re-parse: it reads taxa['genus'] and ranks by
    | delta. Bin-artefact genus names (incertae sedis, sensu stricto, unclassified,
    | the Roman-numeral Clostridium clusters) are skipped from the SUGGESTED default
    | only — the later editable field can add any back.
    */

    /** |delta| (pp) a genus must move to be a candidate key change. */
    public const KEY_CHANGE_MIN_DELTA = 0.5;

    /** |delta| (pp) at/above which a move is labelled "Marked". */
    public const KEY_CHANGE_MARKED_DELTA = 2.0;

    /** Abundance (%) below which a side counts as absent (→ New / Resolved). */
    public const KEY_CHANGE_FLOOR = 0.05;

    /** How many rows each of increases/decreases keeps. */
    public const KEY_CHANGE_LIMIT = 4;

    /**
     * Suggested Key Changes from a frozen retest_comparison.
     *   increases: genus delta >= +MIN, sorted by delta desc, top LIMIT.
     *   decreases: genus delta <= -MIN, sorted by delta asc (most negative first), top LIMIT.
     * Each row: {name, original_pct, retest_pct, label}. Names are cleaned and
     * bin-artefacts filtered (default list only).
     *
     * @return array{increases: array<int, array{name:string, original_pct:float, retest_pct:float, label:string}>, decreases: array<int, array{name:string, original_pct:float, retest_pct:float, label:string}>}
     */
    public static function keyChangesDefault(array $comparison): array
    {
        $clean = [];
        foreach (($comparison['taxa']['genus'] ?? []) as $g) {
            $name = self::cleanName((string) ($g['name'] ?? ''));
            if ($name === '' || self::isBinArtefact($name)) {
                continue;
            }
            $clean[] = [
                'name' => $name,
                'original_pct' => (float) ($g['original_pct'] ?? 0),
                'retest_pct' => (float) ($g['retest_pct'] ?? 0),
                'delta' => (float) ($g['delta'] ?? 0),
            ];
        }

        $increases = array_values(array_filter($clean, fn (array $r): bool => $r['delta'] >= self::KEY_CHANGE_MIN_DELTA));
        usort($increases, fn (array $a, array $b): int => $b['delta'] <=> $a['delta'] ?: strcmp($a['name'], $b['name']));
        $increases = array_map(
            fn (array $r): array => self::keyChangeRow($r, self::increaseLabel($r['original_pct'], $r['delta'])),
            array_slice($increases, 0, self::KEY_CHANGE_LIMIT),
        );

        $decreases = array_values(array_filter($clean, fn (array $r): bool => $r['delta'] <= -self::KEY_CHANGE_MIN_DELTA));
        usort($decreases, fn (array $a, array $b): int => $a['delta'] <=> $b['delta'] ?: strcmp($a['name'], $b['name']));
        $decreases = array_map(
            fn (array $r): array => self::keyChangeRow($r, self::decreaseLabel($r['retest_pct'], $r['delta'])),
            array_slice($decreases, 0, self::KEY_CHANGE_LIMIT),
        );

        return ['increases' => $increases, 'decreases' => $decreases];
    }

    /** Shape one output row (drops the internal delta). */
    private static function keyChangeRow(array $r, string $label): array
    {
        return [
            'name' => $r['name'],
            'original_pct' => $r['original_pct'],
            'retest_pct' => $r['retest_pct'],
            'label' => $label,
        ];
    }

    /** Factual increase label: New (was absent) / Marked increase / Increased. */
    private static function increaseLabel(float $original, float $delta): string
    {
        if ($original < self::KEY_CHANGE_FLOOR) {
            return 'New';
        }

        return $delta >= self::KEY_CHANGE_MARKED_DELTA ? 'Marked increase' : 'Increased';
    }

    /** Factual decrease label: Resolved (now absent) / Marked reduction / Reduced. */
    private static function decreaseLabel(float $retest, float $delta): string
    {
        if ($retest < self::KEY_CHANGE_FLOOR) {
            return 'Resolved';
        }

        return $delta <= -self::KEY_CHANGE_MARKED_DELTA ? 'Marked reduction' : 'Reduced';
    }

    /**
     * Taxonomy-bin artefacts that aren't client-ready, excluded from the SUGGESTED
     * default only: "incertae [sedis]", "sensu stricto", "unclassified", and the
     * Roman-numeral Clostridium clusters (e.g. Clostridium III / IV / XI / XlVa /
     * XVIII). Matches the already-cleaned (space-separated) name, case-insensitively.
     */
    private static function isBinArtefact(string $name): bool
    {
        $n = strtolower($name);

        if (str_contains($n, 'incertae')
            || str_contains($n, 'sensu stricto') || str_contains($n, 'sensu_stricto')
            || str_contains($n, 'unclassified')) {
            return true;
        }

        // "Clostridium" followed only by a Roman-numeral token (optional a/b suffix),
        // e.g. "Clostridium XlVa", "Clostridium XVIII" — the DGGE cluster bins.
        return (bool) preg_match('/^clostridium[\s_]+[ivxl]+[ab]?$/i', $name);
    }
}
