<?php

namespace Tests\Unit;

use App\Services\CsvParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stage 1 of the deterministic health-insights rework (DATA only): the parser
 * captures the SPECIFIC genus percentages the forthcoming rules need — Blautia and
 * the combined Escherichia/Shigella genus — into csv_data['insight_taxa'],
 * regardless of top-20 ranking, with robust matching of the Escherichia/Shigella
 * separator variants. Purely ADDITIVE: top_taxa and the aggregate metrics are
 * unchanged. No rules/display are built here.
 */
class CsvParserInsightTaxaTest extends TestCase
{
    /** Write a CSV with the real lab header to a temp path and return it. */
    private function csv(string $body): string
    {
        $header = 'Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits';
        $path = tempnam(sys_get_temp_dir(), 'insight').'.csv';
        file_put_contents($path, $header."\n".$body);

        return $path;
    }

    public function test_captures_blautia_and_escherichia_shigella_regardless_of_ranking(): void
    {
        // 25 high-% filler genera bury the two low-abundance targets well past the
        // top-20 cap, proving insight_taxa is independent of top_taxa ranking.
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = "Bacteria,Firmicutes,Clostridia,Clostridiales,Fam{$i},Filler{$i},Filler{$i}_sp(ACC{$i}),100,20.00";
        }
        // Blautia across two species rows → genus rollup = 0.80 + 0.40 = 1.20.
        $rows[] = 'Bacteria,Firmicutes,Clostridia,Clostridiales,Lachnospiraceae,Blautia,Blautia_producta(AB001),40,0.80';
        $rows[] = 'Bacteria,Firmicutes,Clostridia,Clostridiales,Lachnospiraceae,Blautia,Blautia_coccoides(AB002),20,0.40';
        // Escherichia/Shigella (combined SILVA genus), low abundance = 0.15.
        $rows[] = 'Bacteria,Proteobacteria,Gammaproteobacteria,Enterobacterales,Enterobacteriaceae,Escherichia-Shigella,Escherichia_coli(X001),8,0.15';

        $path = $this->csv(implode("\n", $rows));
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $this->assertArrayHasKey('insight_taxa', $result);
        $this->assertSame(1.20, $result['insight_taxa']['blautia']);
        $this->assertSame(0.15, $result['insight_taxa']['escherichia_shigella']);

        // Both targets are absent from the (capped, 20.00-dominated) top_taxa —
        // this is exactly why insight_taxa exists.
        $topNames = array_column($result['top_taxa'], 'name');
        $this->assertNotContains('Blautia', $topNames);
        $this->assertNotContains('Escherichia-Shigella', $topNames);
    }

    /**
     * The combined genus reads the same total whatever the separator (and even when
     * a lab splits it into two rows), because matching is token-based.
     *
     */
    #[DataProvider('escherichiaShigellaVariants')]
    public function test_escherichia_shigella_naming_variants_are_matched(array $genusRows, float $expected): void
    {
        $rows = [];
        foreach ($genusRows as [$genus, $pct]) {
            $rows[] = "Bacteria,Proteobacteria,Gamma,Enterobacterales,Enterobacteriaceae,{$genus},{$genus}_sp(ACC),10,{$pct}";
        }
        $path = $this->csv(implode("\n", $rows));
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $this->assertSame($expected, $result['insight_taxa']['escherichia_shigella']);
    }

    public static function escherichiaShigellaVariants(): array
    {
        return [
            'hyphen' => [[['Escherichia-Shigella', '0.30']], 0.30],
            'underscore' => [[['Escherichia_Shigella', '0.30']], 0.30],
            'slash' => [[['Escherichia/Shigella', '0.30']], 0.30],
            'space' => [[['Escherichia Shigella', '0.30']], 0.30],
            'split into two rows' => [[['Escherichia', '0.20'], ['Shigella', '0.10']], 0.30],
        ];
    }

    public function test_absent_genera_are_stored_as_zero_not_missing(): void
    {
        // A sample with neither target genus present.
        $path = $this->csv('Bacteria,Firmicutes,Clostridia,Clostridiales,Fam,Faecalibacterium,Faecalibacterium_prausnitzii(ACC),100,12.50');
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $this->assertArrayHasKey('blautia', $result['insight_taxa']);
        $this->assertArrayHasKey('escherichia_shigella', $result['insight_taxa']);
        $this->assertSame(0.0, $result['insight_taxa']['blautia']);
        $this->assertSame(0.0, $result['insight_taxa']['escherichia_shigella']);
    }

    public function test_change_is_additive_top_taxa_and_metrics_unchanged(): void
    {
        $path = $this->csv('Bacteria,Firmicutes,Clostridia,Clostridiales,Lachnospiraceae,Blautia,Blautia_producta(AB001),40,0.80');
        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        // Everything the pipeline already relied on is still present.
        foreach (['phylum_totals', 'diversity_score', 'species_richness', 'dysbiosis_score', 'microbiome_classification', 'top_taxa'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        // Blautia still ranks in top_taxa too (additive, not a move).
        $this->assertContains('Blautia', array_column($result['top_taxa'], 'name'));
    }
}
