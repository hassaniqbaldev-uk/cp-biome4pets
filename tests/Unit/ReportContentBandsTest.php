<?php

namespace Tests\Unit;

use App\Support\ReportContent;
use PHPUnit\Framework\TestCase;

/**
 * #3 — the clinical interpretation bands (Diversity / Richness / Dysbiosis) and
 * the microbiome classification now live in ONE place (ReportContent), consumed
 * by the web report, the PDF report and CsvParserService. These tests pin the
 * UNCHANGED boundaries (this was a refactor, not a re-threshold) and prove the
 * classification badge and the displayed bands reference the same cutoffs, so
 * they can't drift apart.
 */
class ReportContentBandsTest extends TestCase
{
    public function test_band_cutoff_constants_are_unchanged(): void
    {
        // Exactly the values that were triplicated across the views + parser.
        $this->assertSame(1.9, ReportContent::DIVERSITY_LOW_MAX);
        $this->assertSame(2.5, ReportContent::DIVERSITY_HIGH_MIN);
        $this->assertSame(3.0, ReportContent::DIVERSITY_STABLE_MIN);
        $this->assertSame(400, ReportContent::RICHNESS_LOW_MAX);
        $this->assertSame(650, ReportContent::RICHNESS_HEALTHY_MIN);
        $this->assertSame(0.2, ReportContent::DYSBIOSIS_HEALTHY_MIN);
        $this->assertSame(0.5, ReportContent::DYSBIOSIS_HEALTHY_MAX);
    }

    public function test_diversity_band_labels_and_boundaries(): void
    {
        // < 1.9 Low; 1.9–2.5 Medium (inclusive upper); > 2.5 High — exact original semantics.
        $this->assertSame('Low', ReportContent::diversityBand(1.0)['label']);
        $this->assertSame('Low', ReportContent::diversityBand(1.89)['label']);
        $this->assertSame('Medium', ReportContent::diversityBand(1.9)['label']);   // boundary → Medium
        $this->assertSame('Medium', ReportContent::diversityBand(2.5)['label']);   // boundary → Medium
        $this->assertSame('High', ReportContent::diversityBand(2.51)['label']);

        $this->assertSame('bad', ReportContent::diversityBand(1.0)['tone']);
        $this->assertSame('warn', ReportContent::diversityBand(2.0)['tone']);
        $this->assertSame('good', ReportContent::diversityBand(3.0)['tone']);
    }

    public function test_richness_band_labels_and_boundaries(): void
    {
        $this->assertSame('Low', ReportContent::richnessBand(399)['label']);
        $this->assertSame('Moderate', ReportContent::richnessBand(400)['label']);  // boundary → Moderate
        $this->assertSame('Moderate', ReportContent::richnessBand(650)['label']);  // boundary → Moderate
        $this->assertSame('Healthy', ReportContent::richnessBand(651)['label']);
    }

    public function test_dysbiosis_band_labels_boundaries_and_low_is_a_warning(): void
    {
        $this->assertSame('Low', ReportContent::dysbiosisBand(0.1)['label']);
        $this->assertSame('Healthy', ReportContent::dysbiosisBand(0.2)['label']);  // boundary → Healthy
        $this->assertSame('Healthy', ReportContent::dysbiosisBand(0.5)['label']);  // boundary → Healthy
        $this->assertSame('High', ReportContent::dysbiosisBand(0.6)['label']);

        // Dysbiosis "Low" is amber (warn), NOT green — preserved from the original.
        $this->assertSame('warn', ReportContent::dysbiosisBand(0.1)['tone']);
    }

    public function test_classification_matches_the_original_rules(): void
    {
        // Stable: diversity ≥ 3.0 AND dysbiosis within the Healthy band [0.2, 0.5].
        $this->assertSame('Stable', ReportContent::classify(3.0, 700, 0.3));
        // ≥ 3.0 but dysbiosis out of the Healthy band → not Stable.
        $this->assertSame('Imbalanced', ReportContent::classify(3.0, 700, 0.6));
        // diversity < 1.9 → Depleted.
        $this->assertSame('Imbalanced & Depleted', ReportContent::classify(1.5, 700, 0.3));
        // richness < 400 → Depleted.
        $this->assertSame('Imbalanced & Depleted', ReportContent::classify(2.0, 300, 0.3));
        // neither Stable nor Depleted → Imbalanced.
        $this->assertSame('Imbalanced', ReportContent::classify(2.0, 700, 0.3));
    }

    public function test_classification_shares_cutoffs_with_the_displayed_bands(): void
    {
        // A diversity just below the Low-band cutoff is BOTH a "Low" band AND
        // drives "Imbalanced & Depleted" — same constant, can't drift.
        $justLow = ReportContent::DIVERSITY_LOW_MAX - 0.01;
        $this->assertSame('Low', ReportContent::diversityBand($justLow)['label']);
        $this->assertSame('Imbalanced & Depleted', ReportContent::classify($justLow, 700, 0.3));

        // A richness just below the Low-band cutoff likewise.
        $justSparse = ReportContent::RICHNESS_LOW_MAX - 1;
        $this->assertSame('Low', ReportContent::richnessBand($justSparse)['label']);
        $this->assertSame('Imbalanced & Depleted', ReportContent::classify(2.0, $justSparse, 0.3));
    }

    public function test_legend_range_strings_are_unchanged(): void
    {
        $this->assertSame(
            ['< 1.9', '1.9 - 2.5', '> 2.5'],
            array_column(ReportContent::diversityLegend(), 'range'),
        );
        $this->assertSame(
            ['< 400', '400 - 650', '> 650'],
            array_column(ReportContent::richnessLegend(), 'range'),
        );
        $this->assertSame(
            ['< 0.2', '0.2 - 0.5', '> 0.5'],
            array_column(ReportContent::dysbiosisLegend(), 'range'),
        );
    }

    public function test_num_trims_trailing_zeros(): void
    {
        $this->assertSame('1.9', ReportContent::num(1.9));
        $this->assertSame('400', ReportContent::num(400));
        $this->assertSame('126', ReportContent::num(126.0));
        $this->assertSame('40.5', ReportContent::num(40.5));
    }
}
