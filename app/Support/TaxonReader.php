<?php

namespace App\Support;

/**
 * Rank-aware taxon reader (step 2a): computes the percentage abundance of a NAMED
 * taxon at any taxonomic rank (phylum, class, order, family, genus, species) from a
 * report's lab-summary CSV — the SAME way CsvParserService derives its phylum
 * percentages (summing the matching rows' %_hits, rounded to 2 dp) — so a named
 * lookup and the portal's own phylum figure stay consistent.
 *
 * READ + COMPUTE ONLY. No rules, no plans, no DB, no writes: it reads one CSV once and
 * then answers taxonPercent() / metricPercent() lookups. It exists so a FUTURE retest
 * rule can ask for a metric like "genus:Enterococcus" or "family:Erysipelotrichaceae"
 * and get back a percentage. This step adds the READING only — NOT any rule.
 *
 * It deliberately does not touch App\Support\RetestComparison: that engine's outputs
 * (the frozen phylum/class/genus/species comparison) stay byte-for-byte unchanged.
 * Family coverage — absent from RetestComparison's class/genus/species roll-up — is
 * added HERE, additively, alongside the other ranks.
 */
class TaxonReader
{
    /**
     * Every rank the reader covers, mapped to its CSV column (the lab export carries
     * full taxonomy: Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits).
     */
    public const RANK_COLUMNS = [
        'phylum' => 'Phylum',
        'class' => 'Class',
        'order' => 'Order',
        'family' => 'Family',
        'genus' => 'Genus',
        'species' => 'Species',
    ];

    /**
     * Client taxon names → the normalised CSV key(s) they resolve to. Matching is on
     * normalised tokens (see normaliseKey), so a name whose client spelling ALREADY
     * equals the CSV's needs no correction and maps to itself; the only genuine spelling
     * fix in the client's list is "Flavonifactor" → the CSV's "Flavonifractor".
     *
     * Combined names (Escherichia/Shigella) fold to the same key whatever separator the
     * CSV uses ("/", "-", "_"), so they already match without a map; the entry is kept
     * for documentation AND so that a lab which splits the pair into separate
     * "Escherichia" / "Shigella" rows is folded back into one total.
     *
     * Keyed by the normalised form of the client's name (lower-case, space-joined).
     *
     * @var array<string, array<int,string>>
     */
    public const NAME_ALIASES = [
        // genus
        'enterococcus' => ['enterococcus'],
        'flavonifactor' => ['flavonifractor'],              // client misspelling → CSV spelling
        'escherichia shigella' => ['escherichia shigella', 'escherichia', 'shigella'],
        'campylobacter' => ['campylobacter'],
        'helicobacter' => ['helicobacter'],
        'sutterella' => ['sutterella'],
        'prevotella' => ['prevotella'],
        // family
        'erysipelotrichaceae' => ['erysipelotrichaceae'],
        // class
        'clostridia' => ['clostridia'],
        // species
        'clostridium perfringens' => ['clostridium perfringens'],
        // phylum (accept both the -es and the -ae spelling labs use)
        'spirochaetes' => ['spirochaetes', 'spirochaetae'],
    ];

    /** rank => [normalised CSV key => summed %_hits] (RAW, unrounded, in row order). */
    private array $rollups;

    private function __construct(array $rollups)
    {
        $this->rollups = $rollups;
    }

    /** Build a reader from a lab-summary CSV path. */
    public static function fromCsv(string $csvPath): self
    {
        return new self(self::rollups($csvPath));
    }

    /**
     * The percentage of a named taxon at the given rank: the sum of the %_hits of every
     * row whose rank cell resolves to that name (after accession/prefix cleaning and
     * alias mapping), rounded to 2 dp — exactly as CsvParserService rounds its phylum
     * totals, so taxonPercent('phylum', X) reproduces the portal's phylum_totals[X].
     *
     * An unknown rank, an empty name, or an absent taxon returns 0.0 — never an error.
     */
    public function taxonPercent(string $rank, string $name): float
    {
        $rank = strtolower(trim($rank));
        if (! array_key_exists($rank, self::RANK_COLUMNS)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach (self::acceptedKeys($name) as $key) {
            $sum += $this->rollups[$rank][$key] ?? 0.0;
        }

        return round($sum, 2);
    }

    /**
     * Resolve a "rank:name" metric string (e.g. "genus:Enterococcus") to its percentage.
     * A malformed metric (no colon, or an unknown rank) returns 0.0. This is ONLY the
     * lookup a future rule layer will call — it contains no rule/threshold logic.
     */
    public function metricPercent(string $metric): float
    {
        if (! str_contains($metric, ':')) {
            return 0.0;
        }

        [$rank, $name] = explode(':', $metric, 2);

        return $this->taxonPercent(trim($rank), trim($name));
    }

    /** The normalised CSV keys a requested name accepts (alias map, else itself). */
    private static function acceptedKeys(string $name): array
    {
        $key = self::normaliseKey($name);
        if ($key === '') {
            return [];
        }

        return self::NAME_ALIASES[$key] ?? [$key];
    }

    /**
     * Build rank => [normalised key => summed %_hits] over every CSV row. Sums are kept
     * RAW (unrounded) and accumulated in row order so a single round at lookup time
     * reproduces CsvParserService's phylum totals exactly. Empty cells and any cell
     * containing "Unclassified" are skipped (mirroring the parser's inclusion rule).
     *
     * @return array<string, array<string,float>>
     */
    private static function rollups(string $csvPath): array
    {
        $roll = array_fill_keys(array_keys(self::RANK_COLUMNS), []);

        $handle = @fopen($csvPath, 'r');
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

            foreach (self::RANK_COLUMNS as $rank => $column) {
                $raw = $data[$column] ?? '';
                if ($raw === '' || stripos($raw, 'Unclassified') !== false) {
                    continue;
                }
                $key = self::normaliseKey($raw);
                if ($key === '') {
                    continue;
                }
                $roll[$rank][$key] = ($roll[$rank][$key] ?? 0.0) + $pct;
            }
        }
        fclose($handle);

        return $roll;
    }

    /**
     * Normalise a raw taxon cell (or a requested name) to a lower-case, space-joined
     * key: drop a trailing accession in parentheses, strip SILVA-style rank prefixes
     * (g__ / f__ / c__ …), lower-case, and split on every run of non-alphanumeric
     * characters so "/", "-", "_" and whitespace all separate. Thus "Escherichia-
     * Shigella", "Escherichia/Shigella" and "g__Escherichia_Shigella" all key to
     * "escherichia shigella", and "Clostridium_perfringens(CP000246)" to "clostridium
     * perfringens". Empty / all-separator input yields "".
     */
    private static function normaliseKey(string $raw): string
    {
        $name = preg_replace('/\([^)]*\)\s*$/', '', $raw) ?? $raw;   // strip trailing accession
        $name = preg_replace('/\b[a-z]__/i', '', $name) ?? $name;    // strip g__/f__/c__ … prefixes
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;   // separators → single space
        $name = trim($name);

        return $name;
    }
}
