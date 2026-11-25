<?php

namespace Tests\Unit;

use App\Services\CsvParserService;
use App\Support\TaxonReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rank-aware taxon reader (step 2a): reading a NAMED taxon's
 * percentage at any rank (phylum … species) from a report's lab-summary CSV. Pure —
 * no DB, no Laravel bootstrap: it reads CSV fixtures and asserts computed percentages.
 *
 * The central guarantee is CONSISTENCY: taxonPercent('phylum', X) must reproduce the
 * portal's own phylum_totals[X] from CsvParserService, so a named lookup and the
 * printed phylum figure can never drift apart.
 */
class TaxonReaderTest extends TestCase
{
    private const ORIGINAL = __DIR__.'/../fixtures/retest/teddy_original_S9.csv';

    private const PREFIXED = __DIR__.'/../fixtures/taxon/prefixed_split.csv';

    public function test_phylum_percentages_match_the_portals_own_calculation(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);
        $parse = (new CsvParserService)->parse(self::ORIGINAL);

        // Every phylum the portal reports must come back identical from the reader —
        // this is the consistency contract (same %_hits sum, same 2 dp rounding).
        $this->assertNotEmpty($parse['phylum_totals']);
        foreach ($parse['phylum_totals'] as $name => $pct) {
            $this->assertSame(
                round((float) $pct, 2),
                $reader->taxonPercent('phylum', (string) $name),
                "phylum {$name} should match phylum_totals",
            );
        }
    }

    public function test_reads_a_named_taxon_at_class_family_genus_and_species(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);

        $this->assertSame(3.01, $reader->taxonPercent('phylum', 'Proteobacteria'));
        $this->assertSame(35.12, $reader->taxonPercent('class', 'Clostridia'));
        $this->assertSame(0.77, $reader->taxonPercent('family', 'Erysipelotrichaceae'));
        $this->assertSame(0.03, $reader->taxonPercent('genus', 'Enterococcus'));
        $this->assertSame(2.28, $reader->taxonPercent('species', 'Clostridium perfringens'));
    }

    public function test_combined_escherichia_shigella_matches_the_csv_spelling(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);

        // The CSV spells the combined genus "Escherichia/Shigella"; the lookup must
        // match it whatever separator the caller uses.
        $this->assertSame(0.20, $reader->taxonPercent('genus', 'Escherichia/Shigella'));
        $this->assertSame(0.20, $reader->taxonPercent('genus', 'Escherichia-Shigella'));
        $this->assertSame(0.20, $reader->taxonPercent('genus', 'Escherichia Shigella'));
    }

    public function test_client_misspelling_flavonifactor_resolves_to_csv_flavonifractor(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);

        // Client list says "Flavonifactor"; the CSV spells it "Flavonifractor".
        $this->assertSame(0.03, $reader->taxonPercent('genus', 'Flavonifactor'));
        $this->assertSame(0.03, $reader->taxonPercent('genus', 'Flavonifractor'));
    }

    public function test_exact_token_matching_does_not_bleed_into_sibling_genera(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);

        // Prevotella must NOT absorb Alloprevotella / Paraprevotella, nor Sutterella
        // absorb Parasutterella — matching is on the whole normalised name, not substrings.
        $this->assertSame(0.90, $reader->taxonPercent('genus', 'Prevotella'));
        $this->assertSame(2.24, $reader->taxonPercent('genus', 'Sutterella'));
    }

    public function test_absent_taxon_and_unknown_rank_return_zero_not_an_error(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);

        $this->assertSame(0.0, $reader->taxonPercent('genus', 'Nonexistentbacterium'));
        $this->assertSame(0.0, $reader->taxonPercent('kingdom', 'Bacteria')); // rank not covered
        $this->assertSame(0.0, $reader->taxonPercent('genus', ''));
    }

    public function test_strips_rank_prefixes_and_folds_split_escherichia_shigella(): void
    {
        $reader = TaxonReader::fromCsv(self::PREFIXED);

        // g__Enterococcus → Enterococcus (prefix stripped).
        $this->assertSame(1.50, $reader->taxonPercent('genus', 'Enterococcus'));

        // Separate g__Escherichia (5.00) + g__Shigella (2.00) rows fold to one total.
        $this->assertSame(7.00, $reader->taxonPercent('genus', 'Escherichia/Shigella'));
    }

    public function test_metric_string_resolves_rank_and_name(): void
    {
        $reader = TaxonReader::fromCsv(self::ORIGINAL);

        $this->assertSame(0.03, $reader->metricPercent('genus:Enterococcus'));
        $this->assertSame(35.12, $reader->metricPercent('class:Clostridia'));
        $this->assertSame(0.77, $reader->metricPercent(' family : Erysipelotrichaceae '));

        // Malformed metrics never throw.
        $this->assertSame(0.0, $reader->metricPercent('no-colon'));
        $this->assertSame(0.0, $reader->metricPercent('bogusrank:Firmicutes'));
    }

    public function test_missing_csv_file_yields_zeroes_not_an_error(): void
    {
        $reader = TaxonReader::fromCsv(__DIR__.'/../fixtures/does-not-exist.csv');

        $this->assertSame(0.0, $reader->taxonPercent('phylum', 'Firmicutes'));
    }
}
