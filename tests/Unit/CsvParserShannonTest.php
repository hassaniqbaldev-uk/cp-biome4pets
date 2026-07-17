<?php

namespace Tests\Unit;

use App\Services\CsvParserService;
use PHPUnit\Framework\TestCase;

/**
 * The Shannon diversity fix (report-4050 bug).
 *
 * H = -Σ p·ln(p) requires Σp = 1. The old code used a hardcoded p = %_hits/100, so
 * when part of a sample's abundance sat in rows that only classify to genus/family
 * (blank Species — correctly excluded from the taxa set), the remaining proportions
 * summed to < 1.0 and H was systematically UNDERESTIMATED. Report 4050: species rows
 * carry 83.065% of abundance → old method 2.37 (wrong, stored), renormalised 2.67
 * (correct, the lab's value). These tests lock the renormalisation, the unchanged
 * behaviour on fully-resolved samples, and the num_hits/%_hits unit handling.
 */
class CsvParserShannonTest extends TestCase
{
    private const HEADER = 'Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits';

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shannon').'.csv';
        file_put_contents($path, self::HEADER."\n".implode("\n", $rows));

        return $path;
    }

    /** A zipf-shaped species abundance vector summing to $subtotal percent. */
    private function speciesPcts(int $n, float $exponent, float $subtotal): array
    {
        $w = [];
        for ($i = 1; $i <= $n; $i++) {
            $w[] = 1 / pow($i, $exponent);
        }
        $sum = array_sum($w);

        return array_map(fn (float $v): float => $v / $sum * $subtotal, $w);
    }

    /**
     * The headline regression: a 4050-shaped CSV — 253 species-resolved rows carrying
     * 83.065% of abundance, plus 149 blank-Species rows carrying the other 16.904% —
     * must yield the lab's 2.67, not the old 2.37.
     */
    public function test_renormalised_shannon_reproduces_267_for_a_4050_shaped_csv(): void
    {
        // Exponent 1.514 gives a 253-species shape whose renormalised entropy is 2.67,
        // matching report 4050's real evenness.
        $speciesPcts = $this->speciesPcts(253, 1.514, 83.065);
        $blankPcts = $this->speciesPcts(149, 0.5, 16.904);

        $rows = [];
        foreach ($speciesPcts as $i => $p) {
            $n = (int) round($p * 10000);
            $rows[] = "Bacteria,Firmicutes,C,O,F,Genus{$i},Species_{$i}(ACC{$i}),{$n},".number_format($p, 3, '.', '');
        }
        // Blank Species = classified only to genus/family. Carries real abundance but
        // is NOT part of the taxa set (the lab's Shannon is classified-species only).
        foreach ($blankPcts as $i => $p) {
            $n = (int) round($p * 10000);
            $rows[] = "Bacteria,Firmicutes,C,O,F,GenusOnly{$i},,{$n},".number_format($p, 3, '.', '');
        }

        $path = $this->csv($rows);
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $this->assertSame(2.67, $result['diversity_score'], 'must reproduce the lab-correct 2.67, not the old 2.37');

        // Lock the regression: the OLD hardcoded /100 method on the same species rows
        // produces the wrong ~2.37 that was stored on report 4050.
        $old = 0.0;
        foreach ($speciesPcts as $p) {
            $q = $p / 100;
            $old -= $q * log($q);
        }
        $this->assertSame(2.37, round($old, 2), 'sanity: the old method is the 2.37 we are fixing');
    }

    /**
     * The renormalisation identity, verified against report 4050's REAL confirmed
     * figures: H_correct = H_old/s + ln(s), where s is the included subtotal.
     */
    public function test_renormalisation_identity_matches_report_4050_real_numbers(): void
    {
        $hOld = 2.3729;    // the buggy /100 result actually stored on 4050
        $s = 0.83065;      // species-resolved abundance subtotal

        $hNew = $hOld / $s + log($s);

        $this->assertEqualsWithDelta(2.6711, $hNew, 0.0005);
        $this->assertSame(2.67, round($hNew, 2));
    }

    /** A fully-resolved sample (all abundance in species rows) is UNCHANGED by the
     *  fix — the subtotal is already ~100, so renormalising is a no-op. This is why
     *  most reports were correct. */
    public function test_fully_resolved_sample_is_unchanged_by_renormalisation(): void
    {
        $pcts = $this->speciesPcts(40, 1.2, 100.0);   // 100% resolved to species

        $rows = [];
        foreach ($pcts as $i => $p) {
            $n = (int) round($p * 10000);
            $rows[] = "Bacteria,Firmicutes,C,O,F,G{$i},Species_{$i},{$n},".number_format($p, 4, '.', '');
        }
        $path = $this->csv($rows);
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        // The old /100 method and the renormalised method agree when the subtotal is 100.
        $old = 0.0;
        foreach ($pcts as $p) {
            $q = $p / 100;
            $old -= $q * log($q);
        }

        $this->assertSame(round($old, 2), $result['diversity_score']);
    }

    /** Proportions renormalise to exactly 1.0 over the included set, so H is a valid
     *  Shannon index. Verified via the ln(n) identity: n equally-abundant species
     *  carrying only PART of the sample's abundance must still give exactly ln(n). */
    public function test_proportions_sum_to_one_so_equal_species_give_ln_n(): void
    {
        // 4 equal species carrying 10% each (40% total) + a blank-Species row with 60%.
        $rows = [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,100,10',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,100,10',
            'Bacteria,Firmicutes,C,O,F,G3,Species_3,100,10',
            'Bacteria,Firmicutes,C,O,F,G4,Species_4,100,10',
            'Bacteria,Firmicutes,C,O,F,G5,,600,60',
        ];
        $path = $this->csv($rows);
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        // Renormalised: each p = 1/4 → H = ln(4) = 1.3863 → 1.39. (The old method gave
        // -4*(0.1*ln0.1) = 0.92 — the underestimate this fix removes.)
        $this->assertSame(round(log(4), 2), $result['diversity_score']);
        $this->assertSame(1.39, $result['diversity_score']);
    }

    /** num_hits (exact) is preferred over the lab's pre-rounded %_hits. */
    public function test_num_hits_is_used_in_preference_to_rounded_pct_hits(): void
    {
        // True counts 1:1:2 → H = 1.04. The %_hits column is coarsely rounded to
        // 33/33/33, which would give ln(3) = 1.10 if it were used instead.
        $rows = [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,1,33',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,1,33',
            'Bacteria,Firmicutes,C,O,F,G3,Species_3,2,33',
        ];
        $path = $this->csv($rows);
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $expected = round(-(0.25 * log(0.25) + 0.25 * log(0.25) + 0.5 * log(0.5)), 2);
        $this->assertSame(1.04, $expected);
        $this->assertSame(1.04, $result['diversity_score'], 'must use exact num_hits, not rounded %_hits');
    }

    /** When num_hits is unusable, the whole sample falls back to %_hits (units are
     *  never mixed within one normalisation). */
    public function test_falls_back_to_pct_hits_when_num_hits_missing_or_zero(): void
    {
        $rows = [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,0,33',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,0,33',
            'Bacteria,Firmicutes,C,O,F,G3,Species_3,0,33',
        ];
        $path = $this->csv($rows);
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        // Falls back to %_hits: three equal rows → ln(3) = 1.10. Still renormalised.
        $this->assertSame(1.10, $result['diversity_score']);
    }

    /** Blank-Species rows stay OUT of the taxa set (the lab's method is classified
     *  species only). Including them would give a materially different figure. */
    public function test_blank_species_rows_are_excluded_from_the_taxa_set(): void
    {
        $rows = [
            'Bacteria,Firmicutes,C,O,F,G1,Species_1,100,25',
            'Bacteria,Firmicutes,C,O,F,G2,Species_2,100,25',
            'Bacteria,Firmicutes,C,O,F,G3,,200,50',   // blank Species — excluded
        ];
        $path = $this->csv($rows);
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        // Only the two species rows count, renormalised to 0.5/0.5 → ln(2) = 0.69.
        // (Had the blank row been included it would be ln(2)+ ~= 1.04.)
        $this->assertSame(0.69, $result['diversity_score']);
    }
}
