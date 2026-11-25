<?php

namespace Tests\Unit;

use App\Support\RetestPlanRules;
use App\Support\TaxonReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the retest rule ENGINE (step 2b): RetestPlanRules::selectRetestRule —
 * which rule wins given this retest's CSV (via TaxonReader) and the frozen comparison.
 * Pure: no DB, no Laravel. Threshold CSVs are written to temp files; the FMT delta rule
 * is driven by a crafted comparison array. Plan resolution by key is NOT tested here —
 * that lives in ReportGeneration (RetestRulesOverrideTest).
 */
class RetestRulesEngineTest extends TestCase
{
    /** @var array<int,string> temp CSV paths to clean up */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        $this->tmp = [];
        parent::tearDown();
    }

    /**
     * A TaxonReader over a throwaway CSV built from the given rows. Each row is an assoc
     * array; unspecified rank columns are left blank. %_hits is taken from 'pct'.
     *
     * @param  array<int, array<string,mixed>>  $rows
     */
    private function readerFor(array $rows): TaxonReader
    {
        $path = tempnam(sys_get_temp_dir(), 'tr_').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, ['Kingdom', 'Phylum', 'Class', 'Order', 'Family', 'Genus', 'Species', 'num_hits', '%_hits']);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['kingdom'] ?? 'Bacteria',
                $r['phylum'] ?? '',
                $r['class'] ?? '',
                $r['order'] ?? '',
                $r['family'] ?? '',
                $r['genus'] ?? '',
                $r['species'] ?? '',
                $r['num_hits'] ?? 100,
                $r['pct'] ?? 0,
            ]);
        }
        fclose($fh);
        $this->tmp[] = $path;

        return TaxonReader::fromCsv($path);
    }

    /** A comparison that fires the FMT delta rule (richness −53%, diversity −1.14). */
    private function firingFmtComparison(): array
    {
        return [
            'overview' => [
                'original' => ['diversity' => 3.16, 'species_richness' => 463],
                'retest' => ['diversity' => 2.02, 'species_richness' => 215],
            ],
            'taxa' => [],
        ];
    }

    public function test_each_threshold_rule_wins_in_isolation(): void
    {
        $cases = [
            'spirochaetes' => [['phylum' => 'Spirochaetes', 'pct' => 3.0], 'retest-spirochaetes'],
            'escherichia_shigella' => [['genus' => 'Escherichia/Shigella', 'pct' => 2.5], 'retest-amr-only'],
            'enterococcus' => [['genus' => 'Enterococcus', 'pct' => 6.0], 'reset-recover'],
            'erysipelotrichaceae' => [['family' => 'Erysipelotrichaceae', 'pct' => 3.0], 'retest-prebiotic-only'],
            'sutterella' => [['genus' => 'Sutterella', 'pct' => 6.0], 'maintain-protect'],
            // The client's "Flavonifactor" is spelled "Flavonifractor" in the CSV.
            'flavonifactor' => [['genus' => 'Flavonifractor', 'pct' => 2.5], 'maintain-protect'],
        ];

        foreach ($cases as $expectedKey => [$row, $expectedPlan]) {
            $rule = RetestPlanRules::selectRetestRule($this->readerFor([$row]), null);
            $this->assertNotNull($rule, "{$expectedKey} should fire");
            $this->assertSame($expectedKey, $rule['key']);
            $this->assertSame($expectedPlan, $rule['plan_key']);
            $this->assertSame('retest_'.$expectedKey, $rule['reason']);
        }
    }

    public function test_pathogens_rule_fires_on_any_of_its_three_taxa(): void
    {
        foreach ([
            ['genus' => 'Campylobacter', 'pct' => 2.5],
            ['genus' => 'Helicobacter', 'pct' => 2.5],
            ['species' => 'Clostridium perfringens', 'pct' => 2.5],
        ] as $row) {
            $rule = RetestPlanRules::selectRetestRule($this->readerFor([$row]), null);
            $this->assertNotNull($rule);
            $this->assertSame('pathogens', $rule['key']);
            $this->assertSame('retest-amr-only', $rule['plan_key']);
        }
    }

    public function test_fmt_delta_rule_wins_from_comparison_with_no_csv(): void
    {
        $rule = RetestPlanRules::selectRetestRule(null, $this->firingFmtComparison());

        $this->assertNotNull($rule);
        $this->assertSame('fmt_retest', $rule['key']);
        $this->assertSame('rebuild-renew', $rule['plan_key']);
        $this->assertSame('retest_fmt', $rule['reason']);
    }

    public function test_precedence_spirochaetes_beats_sutterella(): void
    {
        $reader = $this->readerFor([
            ['phylum' => 'Spirochaetes', 'pct' => 3.0],
            ['genus' => 'Sutterella', 'pct' => 6.0],
        ]);

        $rule = RetestPlanRules::selectRetestRule($reader, null);
        $this->assertSame('spirochaetes', $rule['key']);
    }

    public function test_precedence_fmt_beats_lower_threshold_rules(): void
    {
        // Escherichia/Shigella (priority 3) also fires, but FMT (priority 2) outranks it.
        $reader = $this->readerFor([['genus' => 'Escherichia/Shigella', 'pct' => 2.5]]);

        $rule = RetestPlanRules::selectRetestRule($reader, $this->firingFmtComparison());
        $this->assertSame('fmt_retest', $rule['key']);
    }

    public function test_nothing_fires_returns_null(): void
    {
        // All taxa below their thresholds, and a comparison that does not meet FMT.
        $reader = $this->readerFor([
            ['phylum' => 'Spirochaetes', 'pct' => 1.0],
            ['genus' => 'Enterococcus', 'pct' => 2.0],
        ]);
        $flat = [
            'overview' => [
                'original' => ['diversity' => 3.16, 'species_richness' => 463],
                'retest' => ['diversity' => 3.10, 'species_richness' => 450],
            ],
        ];

        $this->assertNull(RetestPlanRules::selectRetestRule($reader, $flat));
    }

    public function test_threshold_is_strict_greater_than(): void
    {
        // Spirochaetes exactly at the 2.5 threshold must NOT fire.
        $this->assertNull(RetestPlanRules::selectRetestRule(
            $this->readerFor([['phylum' => 'Spirochaetes', 'pct' => 2.5]]),
            null,
        ));
    }

    public function test_null_reader_and_null_comparison_is_safe(): void
    {
        $this->assertNull(RetestPlanRules::selectRetestRule(null, null));
    }
}
