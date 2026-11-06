<?php

namespace Tests\Unit;

use App\Support\RetestComparison;
use Tests\TestCase;

/**
 * RetestComparison::keyChangesDefault — the suggested "Key Changes" default for a retest,
 * derived deterministically from the frozen genus comparison (biggest movers, bin-artefact
 * names filtered). Built from the real Teddy fixtures (pure, no DB).
 */
class KeyChangesDefaultTest extends TestCase
{
    private array $result;

    protected function setUp(): void
    {
        parent::setUp();
        $comparison = RetestComparison::build(
            base_path('tests/fixtures/retest/teddy_original_S9.csv'),
            base_path('tests/fixtures/retest/teddy_retest_S12.csv'),
        );
        $this->result = RetestComparison::keyChangesDefault($comparison);
    }

    /** Find one row by name in a list, or fail. */
    private function row(array $list, string $name): array
    {
        foreach ($list as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }
        $this->fail("row {$name} not found");
    }

    public function test_shape_and_caps(): void
    {
        $this->assertArrayHasKey('increases', $this->result);
        $this->assertArrayHasKey('decreases', $this->result);
        $this->assertLessThanOrEqual(4, count($this->result['increases']));
        $this->assertLessThanOrEqual(4, count($this->result['decreases']));

        foreach (['increases', 'decreases'] as $side) {
            foreach ($this->result[$side] as $r) {
                $this->assertSame(['name', 'original_pct', 'retest_pct', 'label'], array_keys($r));
            }
        }
    }

    public function test_increases_are_the_biggest_risers_with_factual_labels(): void
    {
        $inc = $this->result['increases'];

        // Sorted by delta descending → Fusobacterium is first and the biggest mover.
        $this->assertSame('Fusobacterium', $inc[0]['name']);

        $fuso = $this->row($inc, 'Fusobacterium');
        $this->assertSame('Marked increase', $fuso['label']);
        $this->assertEqualsWithDelta(22.543, $fuso['original_pct'], 0.02);
        $this->assertEqualsWithDelta(61.224, $fuso['retest_pct'], 0.02);

        $this->assertSame('Marked increase', $this->row($inc, 'Streptococcus')['label']);
        $this->assertSame('Marked increase', $this->row($inc, 'Sutterella')['label']);
    }

    public function test_decreases_are_the_biggest_fallers_with_factual_labels(): void
    {
        $dec = $this->result['decreases'];

        // Sorted by delta ascending (most negative first) → Bacteroides is first.
        $this->assertSame('Bacteroides', $dec[0]['name']);

        $bact = $this->row($dec, 'Bacteroides');
        $this->assertSame('Marked reduction', $bact['label']);
        $this->assertEqualsWithDelta(27.486, $bact['original_pct'], 0.02);
        $this->assertEqualsWithDelta(11.766, $bact['retest_pct'], 0.02);

        $this->assertSame('Marked reduction', $this->row($dec, 'Blautia')['label']);

        // Dropped to ~0 → "Resolved".
        $mega = $this->row($dec, 'Megamonas');
        $this->assertSame('Resolved', $mega['label']);
        $this->assertEqualsWithDelta(0.0, $mega['retest_pct'], 0.05);

        $coll = $this->row($dec, 'Collinsella');
        $this->assertSame('Resolved', $coll['label']);
        $this->assertEqualsWithDelta(0.0, $coll['retest_pct'], 0.05);
    }

    public function test_no_bin_artefacts_appear_in_either_list(): void
    {
        $names = array_merge(
            array_column($this->result['increases'], 'name'),
            array_column($this->result['decreases'], 'name'),
        );

        foreach ($names as $n) {
            $low = strtolower($n);
            $this->assertStringNotContainsString('incertae', $low, "bin artefact leaked: {$n}");
            $this->assertStringNotContainsString('sensu', $low, "bin artefact leaked: {$n}");
            $this->assertStringNotContainsString('unclassified', $low, "bin artefact leaked: {$n}");
        }

        // Specific DGGE clusters that DO move a lot but must be excluded.
        $this->assertNotContains('Clostridium XI', $names);     // delta -9.9
        $this->assertNotContains('Clostridium XlVa', $names);   // delta -3.8
        $this->assertNotContains('Lachnospiracea incertae sedis', $names);
        $this->assertNotContains('Clostridium sensu stricto', $names); // delta +0.89
    }
}
