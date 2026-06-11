<?php

namespace App\Services;

/**
 * Thin wrapper that turns a CSV file into the six raw lab fields, normalised to
 * the column names used on both the Test and (for now) the Report. Extracted
 * from the entangled "Process CSV" action so create, re-parse and (3c) the
 * under-pet Test flow can share one parse-and-shape code path.
 *
 * Pure parse + shape only — no product-rule evaluation or AI generation here.
 */
class LabResultParser
{
    public function __construct(private CsvParserService $csv = new CsvParserService())
    {
    }

    /**
     * Parse an absolute CSV path and return the raw fields keyed for storage:
     * phylum_data, diversity_score, species_richness, dysbiosis_score,
     * microbiome_classification, and csv_data (the full parse result blob).
     */
    public function fromPath(string $absolutePath): array
    {
        $result = $this->csv->parse($absolutePath);

        return [
            'phylum_data' => $result['phylum_totals'],
            'diversity_score' => $result['diversity_score'],
            'species_richness' => $result['species_richness'],
            'dysbiosis_score' => $result['dysbiosis_score'],
            'microbiome_classification' => $result['microbiome_classification'],
            'csv_data' => $result,
        ];
    }
}
