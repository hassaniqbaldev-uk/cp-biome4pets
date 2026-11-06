<?php

namespace Tests\Unit;

use App\Support\ReportContent;
use PHPUnit\Framework\TestCase;

/**
 * The rule-based retest status for a specialist (ReportContent::specialistStatus).
 * Target band [Tlo,Thi], detection floor 0.01, move epsilon 0.02. Covers every branch.
 */
class SpecialistStatusTest extends TestCase
{
    /** Faecalibacterium-style band for most cases: target 3.0–5.0. */
    private function verdict(float $o, float $r, float $tlo = 3.0, float $thi = 5.0): array
    {
        return ReportContent::specialistStatus($o, $r, $tlo, $thi);
    }

    public function test_in_target_range(): void
    {
        $this->assertSame('In target range', $this->verdict(2.0, 4.0)['label']);
        // Boundaries are inclusive.
        $this->assertSame('In target range', $this->verdict(2.0, 3.0)['label']);
        $this->assertSame('In target range', $this->verdict(2.0, 5.0)['label']);
    }

    public function test_above_target(): void
    {
        $this->assertSame('Above target', $this->verdict(4.0, 6.5)['label']);
    }

    public function test_recovering(): void
    {
        // floor <= R < Tlo, O detected, R rose by more than epsilon.
        $this->assertSame('Recovering', $this->verdict(0.40, 0.80)['label']);
    }

    public function test_declining(): void
    {
        // floor <= R < Tlo, R fell by more than epsilon.
        $this->assertSame('Declining', $this->verdict(7.50, 2.87)['label']);
    }

    public function test_below_target(): void
    {
        // floor <= R < Tlo, move within ±epsilon of O.
        $this->assertSame('Below target', $this->verdict(0.361, 0.358)['label']);
    }

    public function test_reappeared(): void
    {
        // O below floor, R back above floor but still under Tlo.
        $this->assertSame('Reappeared', $this->verdict(0.0, 0.50)['label']);
    }

    public function test_lost(): void
    {
        // O was detected, R fell below floor.
        $this->assertSame('Lost', $this->verdict(2.00, 0.0)['label']);
    }

    public function test_not_detected(): void
    {
        // Absent in both tests.
        $this->assertSame('Not detected', $this->verdict(0.0, 0.0)['label']);
    }

    public function test_tone_mapping(): void
    {
        $this->assertSame('good', $this->verdict(2.0, 4.0)['tone']);       // in target
        $this->assertSame('accent', $this->verdict(0.40, 0.80)['tone']);   // recovering
        $this->assertSame('accent', $this->verdict(0.0, 0.50)['tone']);    // reappeared
        $this->assertSame('bad', $this->verdict(7.50, 2.87)['tone']);      // declining
        $this->assertSame('bad', $this->verdict(2.00, 0.0)['tone']);       // lost
        $this->assertSame('muted', $this->verdict(0.0, 0.0)['tone']);      // not detected
        $this->assertSame('muted', $this->verdict(0.361, 0.358)['tone']);  // below target
        $this->assertSame('warn', $this->verdict(4.0, 6.5)['tone']);       // above target
    }
}
