<?php

namespace App\Support;

use App\Filament\Resources\ReportResource;
use App\Models\CatalogProduct;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The INTERPRETATION half of report generation, decomposed out of the entangled
 * "Process CSV" action (Phase 3c). Raw lab parsing lives on the Test (via
 * LabResultParser); this turns a Test's raw inputs + the pet into the report's
 * AI copy, scores and product/plan selection. Shared by:
 *   - the wizard "Process CSV" action (new-CSV path)
 *   - the wizard "Generate from existing test" action
 *   - the "Generate report" action on a Test (PetResource).
 */
class ReportGeneration
{
    /** Pet context for the AI prompt (incl. Phase 2 health notes). */
    public static function petContext(?Pet $pet): array
    {
        return $pet ? [
            'name' => $pet->name,
            'breed' => $pet->breed,
            'sex' => $pet->sex,
            'diet' => $pet->diet,
            'health_notes' => $pet->health_notes,
        ] : [];
    }

    /**
     * Run the AI interpretation for a pet's raw inputs and return the values
     * mapped to Report columns (ai_*, vet_summary, goal, recommended_actions,
     * score_*). On any AI failure every value is '' (the caller can detect this).
     */
    public static function interpretationColumns(array $phylumData, ?float $diversity, ?Pet $pet): array
    {
        $interp = (new OpenAiService())->generateReportInterpretations(
            $phylumData,
            (float) ($diversity ?? 0),
            self::petContext($pet),
        );

        return [
            'ai_summary' => $interp['summary'],
            'ai_bacteroidetes_interpretation' => $interp['bacteroidetes_interpretation'],
            'ai_firmicutes_interpretation' => $interp['firmicutes_interpretation'],
            'ai_fusobacteria_interpretation' => $interp['fusobacteria_interpretation'],
            'ai_proteobacteria_interpretation' => $interp['proteobacteria_interpretation'],
            'ai_diversity_interpretation' => $interp['diversity_interpretation'],
            'vet_summary' => $interp['vet_summary'],
            'goal' => $interp['goal'],
            'recommended_actions' => $interp['recommended_actions'],
            'score_gut_wall' => $interp['score_gut_wall'],
            'score_skin_allergy' => $interp['score_skin_allergy'],
            'score_behaviour_mood' => $interp['score_behaviour_mood'],
            'score_gut_barrier' => $interp['score_gut_barrier'],
            'score_gas_digestive' => $interp['score_gas_digestive'],
            'score_stress_resilience' => $interp['score_stress_resilience'],
        ];
    }

    /**
     * Fire the product rules for the raw inputs and return the matched catalog
     * product ids + the recommended plan id (both derived from the same triggers).
     *
     * @return array{triggered: array<int,string>, catalog_product_ids: array<int,int>, plan_id: ?int}
     */
    public static function productSelection(array $phylumData, ?float $diversity): array
    {
        $triggered = (new CsvParserService())->evaluateProductRules(
            $phylumData,
            (float) ($diversity ?? 0),
        );

        $catalogProductIds = CatalogProduct::active()
            ->whereHas('triggerEntries', fn ($q) => $q->whereIn('trigger', $triggered))
            ->pluck('id')
            ->all();

        return [
            'triggered' => $triggered,
            'catalog_product_ids' => $catalogProductIds,
            'plan_id' => ReportResource::recommendPlanId($triggered),
        ];
    }

    /**
     * Entry A: build a draft Report FROM a Test (the "Generate report" action).
     * pet/client come from the test; AI + product/plan are generated from the
     * test's raw data; the pet snapshot is frozen now. Raw is mirrored onto the
     * report (dual-write) while the report columns still exist. Atomic; also
     * advances the test's status to report_generated. The plan is applied later
     * in the report editor (its subscription_snapshot is captured there).
     */
    public static function createReportFromTest(Test $test): Report
    {
        return DB::transaction(function () use ($test) {
            $pet = $test->pet;
            $interp = self::interpretationColumns($test->phylum_data ?? [], $test->diversity_score, $pet);
            $selection = self::productSelection($test->phylum_data ?? [], $test->diversity_score);

            $report = Report::create(array_merge($interp, [
                'client_id' => $test->client_id ?? $pet?->client_id,
                'pet_id' => $test->pet_id,
                'test_id' => $test->id,
                'sample_id' => $test->sample_id,
                'report_date' => $test->report_date ?? Carbon::today(),
                'status' => 'draft',
                'plan_id' => $selection['plan_id'],
                'pet_snapshot' => Report::buildPetSnapshot($pet),
                // Dual-write raw mirror from the test (source of truth = test).
                'csv_path' => $test->csv_path,
                'csv_data' => $test->csv_data,
                'phylum_data' => $test->phylum_data,
                'diversity_score' => $test->diversity_score,
                'species_richness' => $test->species_richness,
                'dysbiosis_score' => $test->dysbiosis_score,
                'microbiome_classification' => $test->microbiome_classification,
            ]));

            if (! empty($selection['catalog_product_ids'])) {
                $sync = [];
                foreach ($selection['catalog_product_ids'] as $position => $id) {
                    $sync[$id] = ['position' => $position];
                }
                $report->catalogProducts()->sync($sync);
            }

            $test->update(['status' => 'report_generated']);

            return $report;
        });
    }
}
