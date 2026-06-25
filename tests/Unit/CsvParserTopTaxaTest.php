<?php

namespace Tests\Unit;

use App\Services\CsvParserService;
use PHPUnit\Framework\TestCase;

/**
 * Stage 1 retention: the parser keeps the pet's specific bacteria as a ranked
 * top_taxa list — genus rollups (sum of a genus's species rows) PLUS notable
 * species rows, each tagged with its rank, names cleaned for readability, sorted
 * by % desc and capped. Purely ADDITIVE — the existing aggregate metrics are
 * untouched. This is the only specific-bacteria data the pipeline may use.
 */
class CsvParserTopTaxaTest extends TestCase
{
    /** Write a CSV with the real lab header to a temp path and return it. */
    private function csv(string $body): string
    {
        $header = 'Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits';
        $path = tempnam(sys_get_temp_dir(), 'taxa').'.csv';
        file_put_contents($path, $header."\n".$body);

        return $path;
    }

    public function test_top_taxa_retains_genus_rollups_and_species_with_clean_names(): void
    {
        $path = $this->csv(implode("\n", [
            'Bacteria,Fusobacteria,Fusobacteriia,Fusobacteriales,Fusobacteriaceae,Fusobacterium,Fusobacterium_mortiferum(NR_117734.1),6524,11.24',
            'Bacteria,Fusobacteria,Fusobacteriia,Fusobacteriales,Fusobacteriaceae,Fusobacterium,Fusobacterium_perfoetens(M58684),4917,8.48',
            'Bacteria,Bacteroidetes,Bacteroidia,Bacteroidales,Prevotellaceae,Prevotella,Prevotella_copri(AB064923),5754,9.91',
            'Bacteria,Firmicutes,Clostridia,Clostridiales,Clostridiaceae_1,Clostridium_sensu_stricto,Clostridium_perfringens(CP000246),1803,3.11',
            // Unclassified row must be excluded entirely.
            'Bacteria,Unclassified,Unclassified,Unclassified,Unclassified,Unclassified,Unclassified,10,0.5',
        ]));

        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $this->assertArrayHasKey('top_taxa', $result);
        $taxa = $result['top_taxa'];
        $byName = collect($taxa)->keyBy('name');

        // Genus ROLLUP = sum of that genus's species rows (11.24 + 8.48 = 19.72).
        $this->assertSame('genus', $byName['Fusobacterium']['rank']);
        $this->assertSame(19.72, $byName['Fusobacterium']['pct']);

        // Species rows retained individually, with their own %.
        $this->assertSame('species', $byName['Fusobacterium mortiferum']['rank']);
        $this->assertSame(11.24, $byName['Fusobacterium mortiferum']['pct']);

        // Names cleaned: accession dropped, underscores → spaces.
        $this->assertArrayHasKey('Fusobacterium perfoetens', $byName->all());
        $this->assertArrayHasKey('Clostridium sensu stricto', $byName->all()); // genus underscores cleaned
        $this->assertArrayHasKey('Clostridium perfringens', $byName->all());

        // No raw artefacts leak through.
        foreach ($taxa as $t) {
            $this->assertStringNotContainsString('_', $t['name']);
            $this->assertStringNotContainsString('(', $t['name']);
            $this->assertStringNotContainsString('Unclassified', $t['name']);
        }

        // Sorted by % desc — the Fusobacterium genus rollup (19.72) leads.
        $this->assertSame('Fusobacterium', $taxa[0]['name']);

        // Aggregate metrics are still present and untouched (additive change).
        $this->assertArrayHasKey('phylum_totals', $result);
        $this->assertArrayHasKey('diversity_score', $result);
        $this->assertArrayHasKey('species_richness', $result);
    }

    public function test_top_taxa_is_capped(): void
    {
        // 30 distinct genera+species → far more than the cap; result is bounded.
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $pct = number_format(30 - $i + 1 + 0.5, 2, '.', ''); // descending, distinct
            $rows[] = "Bacteria,Firmicutes,Clostridia,Clostridiales,Fam{$i},Genus{$i},Genus{$i}_species{$i}(ACC{$i}),100,{$pct}";
        }
        $path = $this->csv(implode("\n", $rows));

        $result = (new CsvParserService)->parse($path);
        @unlink($path);

        $this->assertLessThanOrEqual(CsvParserService::TOP_TAXA_LIMIT, count($result['top_taxa']));
        $this->assertSame(20, CsvParserService::TOP_TAXA_LIMIT);
    }
}
