<?php

namespace App\Services;

use App\Models\ProductRule;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CsvParserService
{
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("CSV file not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file: {$filePath}");
        }

        $header = fgetcsv($handle);

        if (!is_array($header) || empty($header)) {
            fclose($handle);
            throw new RuntimeException("CSV file has no header row: {$filePath}");
        }

        $header = array_map('trim', $header);
        $headerCount = count($header);

        $phylumTotals = [];
        $speciesProportions = [];
        $speciesRichness = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row)) {
                continue;
            }

            // Skip rows that don't match header column count
            if (count($row) !== $headerCount) {
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));

            if ($data === false) {
                continue;
            }

            $phylum = $data['Phylum'] ?? '';
            $pctHits = (float) ($data['%_hits'] ?? 0);

            if ($phylum !== '' && $phylum !== 'Unclassified') {
                $phylumTotals[$phylum] = ($phylumTotals[$phylum] ?? 0) + $pctHits;
            }

            $species = $data['Species'] ?? '';
            if ($species !== '' && $pctHits > 0) {
                $speciesProportions[] = $pctHits / 100;
            }

            // Count species richness: non-empty, non-null, not containing "Unclassified"
            if ($species !== '' && stripos($species, 'Unclassified') === false) {
                $speciesRichness++;
            }
        }

        fclose($handle);

        $phylumTotals = array_map(fn($v) => round($v, 2), $phylumTotals);

        $diversityScore = 0.0;
        if (count($speciesProportions) > 0) {
            foreach ($speciesProportions as $p) {
                if ($p > 0) {
                    $diversityScore -= $p * log($p);
                }
            }
        }

        $diversityScore = round($diversityScore, 2);

        // Calculate dysbiosis score (Firmicutes / Bacteroidetes ratio)
        $firmicutes = $phylumTotals['Firmicutes'] ?? 0;
        $bacteroidetes = $phylumTotals['Bacteroidetes'] ?? 0;
        $dysbiosisScore = $bacteroidetes > 0 ? round($firmicutes / $bacteroidetes, 2) : 0;

        // Determine microbiome classification
        if ($diversityScore >= 3.0 && $dysbiosisScore >= 0.2 && $dysbiosisScore <= 0.5) {
            $microbiomeClassification = 'Stable';
        } elseif ($diversityScore < 1.9 || $speciesRichness < 400) {
            $microbiomeClassification = 'Imbalanced & Depleted';
        } else {
            $microbiomeClassification = 'Imbalanced';
        }

        return [
            'phylum_totals' => $phylumTotals,
            'diversity_score' => $diversityScore,
            'species_richness' => $speciesRichness,
            'dysbiosis_score' => $dysbiosisScore,
            'microbiome_classification' => $microbiomeClassification,
        ];
    }

    /**
     * Evaluate configurable product rules from the product_rules table against
     * the report's metrics, returning the set of fired trigger names.
     *
     * Same input/output shape as before. If the table is empty or unreadable,
     * falls back to the original hardcoded rules so report generation can
     * never break.
     */
    public function evaluateProductRules(array $phylumTotals, float $diversityScore): array
    {
        // Metrics available to rules: phylum percentages plus the diversity score.
        $metrics = $phylumTotals;
        $metrics['diversity_score'] = $diversityScore;

        try {
            $rules = ProductRule::query()->where('is_active', true)->orderBy('id')->get();
        } catch (\Throwable $e) {
            Log::warning('product_rules unreadable, falling back to hardcoded rules.', ['error' => $e->getMessage()]);

            return $this->evaluateHardcodedProductRules($phylumTotals, $diversityScore);
        }

        if ($rules->isEmpty()) {
            return $this->evaluateHardcodedProductRules($phylumTotals, $diversityScore);
        }

        // Use an ordered set so multiple active rows sharing a trigger_name OR
        // together and the trigger is only reported once.
        $triggered = [];

        foreach ($rules as $rule) {
            if (! array_key_exists($rule->metric, $metrics)) {
                // Warn but don't crash on a rule referencing an unknown metric.
                Log::warning('Product rule references a metric not present in report data; skipping.', [
                    'trigger_name' => $rule->trigger_name,
                    'metric' => $rule->metric,
                ]);

                continue;
            }

            if ($rule->matches((float) $metrics[$rule->metric])) {
                $triggered[$rule->trigger_name] = true;
            }
        }

        return array_keys($triggered);
    }

    /**
     * Hardcoded fallback rules used when product_rules is empty/unreadable.
     * Thresholds mirror the canonical ProductRuleSeeder set so a fresh deploy
     * isn't stale. Returns a flat list of distinct trigger-name strings.
     */
    private function evaluateHardcodedProductRules(array $phylumTotals, float $diversityScore): array
    {
        $triggered = [];

        $bacteroidetes = $phylumTotals['Bacteroidetes'] ?? 0;
        if ($bacteroidetes > 30 || $bacteroidetes < 10) {
            $triggered[] = 'AMR';
        }

        $firmicutes = $phylumTotals['Firmicutes'] ?? 0;
        if ($firmicutes < 18) {
            $triggered[] = 'Prebiotic';
        }

        if ($bacteroidetes > 30) {
            $triggered[] = 'Antimicrobic';
        }

        if ($diversityScore < 1.6) {
            $triggered[] = 'FMT';
        }

        return $triggered;
    }
}
