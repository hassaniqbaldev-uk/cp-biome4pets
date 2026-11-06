<?php

namespace Tests\Unit;

use App\Support\RetestComparison;
use Tests\TestCase;

/**
 * The pass/fail gate for the retest comparison engine. Loads the two real fixtures,
 * runs the comparison, and asserts the overview metrics (diversity + F/B to 2 dp,
 * richness exact) and the per-taxon figures (percentages within 0.02 absolute) plus
 * the delta directions.
 *
 * Diversity is the portal's EXISTING Shannon (species-resolved rows): 3.16 / 2.02 for
 * these samples — not a new all-rows figure. Species are keyed by the portal's cleaned
 * full name, so the brief's "C. hiranonis" is "Clostridium hiranonis" here.
 */
class RetestComparisonTest extends TestCase
{
    private function comparison(): array
    {
        $dir = base_path('tests/fixtures/retest');

        return RetestComparison::build(
            $dir.'/teddy_original_S9.csv',
            $dir.'/teddy_retest_S12.csv',
        );
    }

    /** Find one taxon entry at a level, or null. */
    private function entry(array $result, string $rank, string $name): ?array
    {
        foreach ($result['taxa'][$rank] as $e) {
            if ($e['name'] === $name) {
                return $e;
            }
        }

        return null;
    }

    public function test_overview_metrics_come_verbatim_from_the_portal(): void
    {
        $r = $this->comparison();
        $o = $r['overview']['original'];
        $t = $r['overview']['retest'];

        $this->assertEqualsWithDelta(3.16, $o['diversity'], 0.005);
        $this->assertSame(463, $o['species_richness']);
        $this->assertEqualsWithDelta(1.39, $o['firmicutes_bacteroidetes_ratio'], 0.005);

        $this->assertEqualsWithDelta(2.02, $t['diversity'], 0.005);
        $this->assertSame(215, $t['species_richness']);
        $this->assertEqualsWithDelta(1.66, $t['firmicutes_bacteroidetes_ratio'], 0.005);
    }

    public function test_phylum_comparison(): void
    {
        $r = $this->comparison();
        foreach ([
            ['Fusobacteria', 22.690, 61.677],
            ['Firmicutes', 41.452, 19.691],
            ['Bacteroidetes', 29.841, 11.890],
        ] as [$name, $orig, $retest]) {
            $e = $this->entry($r, 'phylum', $name);
            $this->assertNotNull($e, "phylum {$name} present");
            $this->assertEqualsWithDelta($orig, $e['original_pct'], 0.02, "{$name} original");
            $this->assertEqualsWithDelta($retest, $e['retest_pct'], 0.02, "{$name} retest");
        }
    }

    public function test_genus_comparison(): void
    {
        $r = $this->comparison();
        foreach ([
            ['Fusobacterium', 22.543, 61.224],
            ['Bacteroides', 27.486, 11.766],
            ['Blautia', 7.501, 2.867],
            ['Megamonas', 4.837, 0.007],
            ['Collinsella', 2.171, 0.003],
            ['Sutterella', 2.235, 4.767],
            ['Faecalibacterium', 0.402, 0.800],
        ] as [$name, $orig, $retest]) {
            $e = $this->entry($r, 'genus', $name);
            $this->assertNotNull($e, "genus {$name} present");
            $this->assertEqualsWithDelta($orig, $e['original_pct'], 0.02, "{$name} original");
            $this->assertEqualsWithDelta($retest, $e['retest_pct'], 0.02, "{$name} retest");
        }
    }

    public function test_species_comparison(): void
    {
        $r = $this->comparison();

        $hiranonis = $this->entry($r, 'species', 'Clostridium hiranonis');
        $this->assertNotNull($hiranonis);
        $this->assertEqualsWithDelta(9.886, $hiranonis['original_pct'], 0.02);
        $this->assertEqualsWithDelta(0.469, $hiranonis['retest_pct'], 0.02);

        $plebeius = $this->entry($r, 'species', 'Bacteroides plebeius');
        $this->assertNotNull($plebeius);
        $this->assertEqualsWithDelta(13.624, $plebeius['original_pct'], 0.02);

        $mortiferum = $this->entry($r, 'species', 'Fusobacterium mortiferum');
        $this->assertNotNull($mortiferum);
        $this->assertEqualsWithDelta(33.954, $mortiferum['retest_pct'], 0.02);
    }

    public function test_delta_directions(): void
    {
        $r = $this->comparison();

        $hiranonis = $this->entry($r, 'species', 'Clostridium hiranonis');
        $this->assertNotNull($hiranonis);
        $this->assertSame('down', $hiranonis['direction']);

        $fusobacterium = $this->entry($r, 'genus', 'Fusobacterium');
        $this->assertNotNull($fusobacterium);
        $this->assertSame('up', $fusobacterium['direction']);

        $collinsella = $this->entry($r, 'genus', 'Collinsella');
        $this->assertNotNull($collinsella);
        $this->assertEqualsWithDelta(0.003, $collinsella['retest_pct'], 0.01);
        $this->assertSame('down', $collinsella['direction']);
    }

    public function test_class_level_is_populated(): void
    {
        $r = $this->comparison();
        $this->assertArrayHasKey('class', $r['taxa']);
        $this->assertNotEmpty($r['taxa']['class']);
        // Every entry carries the full comparison shape.
        $first = $r['taxa']['class'][0];
        foreach (['name', 'original_pct', 'retest_pct', 'delta', 'direction'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }
}
