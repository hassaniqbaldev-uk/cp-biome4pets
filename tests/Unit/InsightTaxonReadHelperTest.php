<?php

namespace Tests\Unit;

use App\Models\Report;
use App\Models\Test;
use App\Support\ReportContent;
use PHPUnit\Framework\TestCase;

/**
 * Stage 1 read side: phylumPercent() is naming-robust (old vs new taxonomy) and
 * treats an absent phylum as 0; insightTaxonPercentages() exposes every bacteria
 * the forthcoming insights need as one clean map, defaulting to 0 for reports not
 * yet backfilled. No rule/display logic — data availability only.
 */
class InsightTaxonReadHelperTest extends TestCase
{
    public function test_phylum_percent_matches_old_and_new_taxonomy_names(): void
    {
        // Old canonical name → exact stored value (additive-safe, no drift).
        $this->assertSame(33.7, ReportContent::phylumPercent(['Bacteroidetes' => 33.7], 'Bacteroidetes'));

        // Newer "-ota" names resolve to the same canonical phylum.
        $this->assertSame(1.9, ReportContent::phylumPercent(['Verrucomicrobiota' => 1.9], 'Verrucomicrobia'));
        $this->assertSame(18.3, ReportContent::phylumPercent(['Bacillota' => 18.3], 'Firmicutes'));

        // Case-insensitive.
        $this->assertSame(20.0, ReportContent::phylumPercent(['bacteroidota' => 20.0], 'Bacteroidetes'));
    }

    public function test_phylum_percent_absent_reads_zero(): void
    {
        $this->assertSame(0.0, ReportContent::phylumPercent(['Firmicutes' => 40], 'Verrucomicrobia'));
        $this->assertSame(0.0, ReportContent::phylumPercent([], 'Bacteroidetes'));
    }

    /**
     * Build a Report whose raw lab fields resolve through the Report→Test proxy
     * (as in production), without hitting the DB. The Test's array casts turn the
     * given arrays back into arrays on read.
     */
    private function reportWith(array $phylumData, array $csvData): Report
    {
        $test = new Test(['phylum_data' => $phylumData, 'csv_data' => $csvData]);
        $report = new Report;
        $report->setRelation('test', $test);

        return $report;
    }

    public function test_insight_taxon_percentages_reads_phyla_and_genera(): void
    {
        $report = $this->reportWith(
            phylumData: ['Bacteroidota' => 30.5, 'Firmicutes' => 22.0],
            csvData: ['insight_taxa' => ['blautia' => 1.2, 'escherichia_shigella' => 0.15]],
        );

        $map = ReportContent::insightTaxonPercentages($report);

        $this->assertSame(30.5, $map['Bacteroidetes']);   // matched via 'Bacteroidota'
        $this->assertSame(22.0, $map['Firmicutes']);
        $this->assertSame(0.0, $map['Verrucomicrobia']);  // absent → 0
        $this->assertSame(1.2, $map['Blautia']);
        $this->assertSame(0.15, $map['Escherichia/Shigella']);
    }

    public function test_insight_taxon_percentages_defaults_to_zero_before_backfill(): void
    {
        // A pre-Stage-1 report: no insight_taxa key at all.
        $report = $this->reportWith(
            phylumData: ['Firmicutes' => 40.0],
            csvData: ['phylum_totals' => ['Firmicutes' => 40.0]],
        );

        $map = ReportContent::insightTaxonPercentages($report);

        $this->assertSame(0.0, $map['Blautia']);
        $this->assertSame(0.0, $map['Escherichia/Shigella']);
        $this->assertSame(40.0, $map['Firmicutes']);

        // Single-value accessor mirrors the map; unknown name → 0.
        $this->assertSame(40.0, ReportContent::insightTaxonPercent($report, 'Firmicutes'));
        $this->assertSame(0.0, ReportContent::insightTaxonPercent($report, 'Nonexistent'));
    }
}
