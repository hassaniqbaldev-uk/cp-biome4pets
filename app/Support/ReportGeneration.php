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
use Illuminate\Support\Facades\Log;

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
    /**
     * Pet context for the AI prompt (incl. health notes). Part 2: the notes are
     * the date-filtered history AS OF $asOf (the report/test date) so the copy is
     * grounded in what was known at that point in time.
     */
    public static function petContext(?Pet $pet, Carbon|string|null $asOf = null): array
    {
        return $pet ? [
            'name' => $pet->name,
            'breed' => $pet->breed,
            'sex' => $pet->sex,
            'diet' => $pet->diet,
            'health_notes' => $pet->healthNotesForContext($asOf),
        ] : [];
    }

    /**
     * Run the AI interpretation for a pet's raw inputs and return the values
     * mapped to Report columns (ai_*, vet_summary, goal, recommended_actions,
     * score_*). On any AI failure every value is '' (the caller can detect this).
     */
    public static function interpretationColumns(array $phylumData, ?float $diversity, ?Pet $pet, Carbon|string|null $asOf = null, ?string &$generationError = null, array $deterministic = []): array
    {
        // Phase 2: keep the service instance so its observe-only failure reason
        // ('api_failed' / 'json_parse_failed' / null) can be handed to the quality
        // validator via the optional by-ref out-param. Existing callers that omit
        // the param are unaffected.
        //
        // $deterministic carries species_richness / dysbiosis_score /
        // microbiome_classification so the prose is grounded in the SAME findings
        // the badge shows (it was previously blind to depletion). Computation is
        // unchanged — these values are passed through as fixed facts, read-only.
        $service = new OpenAiService;
        $interp = $service->generateReportInterpretations(
            $phylumData,
            (float) ($diversity ?? 0),
            self::petContext($pet, $asOf),
            $deterministic,
        );
        $generationError = $service->lastErrorCode;

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
    public static function productSelection(array $phylumData, ?float $diversity, ?string $classification = null): array
    {
        $triggered = (new CsvParserService)->evaluateProductRules(
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
            // The classification gates the maintenance fallback: an unwell pet that
            // fires no trigger gets null (→ manual selection) rather than maintenance.
            'plan_id' => ReportResource::recommendPlanId($triggered, $classification),
        ];
    }

    /**
     * Phase 2 (observe-only): grade the just-generated AI output against the
     * deterministic ground truth, log a PII-SAFE summary, and return the verdict
     * so Phase 3 can persist it. This is the single wiring point shared by all
     * three generation paths.
     *
     * The grading itself is pure (ReportQualityValidator); this wrapper builds the
     * context, logs codes/severities/tiers/counts ONLY (never the AI text or any
     * pet PII), and is wrapped in a try/catch so a grader fault can never affect
     * generation — the validator strictly observes.
     *
     * @param  array  $interpretations  the ai_ and score_ columns just generated
     * @param  array  $deterministic  phylum_totals, diversity_score, etc.
     * @param  array  $selection  ['triggered' => [...], 'plan_id' => ?int, ...]
     * @param  array  $meta  PII-safe context for the log line (path, ids)
     */
    public static function gradeAndLog(array $interpretations, array $deterministic, array $selection, ?string $generationError, array $meta = []): array
    {
        try {
            $verdict = ReportQualityValidator::validate([
                'interpretations' => $interpretations,
                'phylum_totals' => $deterministic['phylum_totals'] ?? [],
                'diversity_score' => $deterministic['diversity_score'] ?? null,
                'species_richness' => $deterministic['species_richness'] ?? null,
                'dysbiosis_score' => $deterministic['dysbiosis_score'] ?? null,
                'microbiome_classification' => $deterministic['microbiome_classification'] ?? null,
                'species' => $deterministic['species'] ?? [],
                'triggered' => $selection['triggered'] ?? [],
                'plan_id' => $selection['plan_id'] ?? null,
                'generation_error' => $generationError,
            ]);

            // PII-safe log: codes/severities/tiers/counts only — NOT issue details
            // (which, while non-PII by construction, we still keep out of the log),
            // NOT the AI text, NOT pet PII. Deterministic -> warning; else info.
            $codes = array_map(
                fn (array $i): array => ['code' => $i['code'], 'severity' => $i['severity'], 'tier' => $i['tier']],
                $verdict['issues'],
            );
            $payload = array_merge($meta, [
                'needs_review' => $verdict['needs_review'],
                'counts' => $verdict['counts'],
                'deterministic_count' => $verdict['deterministic_count'],
                'heuristic_count' => $verdict['heuristic_count'],
                'issues' => $codes,
            ]);

            if ($verdict['deterministic_count'] > 0) {
                Log::warning('Report quality: deterministic issues detected', $payload);
            } elseif ($verdict['heuristic_count'] > 0) {
                Log::info('Report quality: heuristic observations (log-only)', $payload);
            } else {
                Log::info('Report quality: clean', $payload);
            }

            return $verdict;
        } catch (\Throwable $e) {
            // Never let grading disturb generation.
            Log::debug('Report quality: grader failed (ignored)', array_merge($meta, ['error' => $e->getMessage()]));

            return [
                'issues' => [],
                'counts' => ['error' => 0, 'warning' => 0, 'info' => 0],
                'deterministic_count' => 0,
                'heuristic_count' => 0,
                'needs_review' => false,
            ];
        }
    }

    /**
     * Phase 3: shape a verdict for persistence in reports.review_flags. Stores the
     * FULL issue list (deterministic AND heuristic — heuristics are kept for the
     * record/tuning, even though they don't drive needs_review) plus a detected_at
     * stamp. Returns null when there are no issues so a clean report stores null.
     */
    public static function reviewFlagsFromVerdict(array $verdict): ?array
    {
        if (empty($verdict['issues'])) {
            return null;
        }

        return [
            'detected_at' => now()->toIso8601String(),
            'issues' => $verdict['issues'],
        ];
    }

    /**
     * Entry A: build a draft Report FROM a Test (the "Generate report" action).
     * pet/client come from the test; AI + product/plan are generated from the
     * test's raw data; the pet snapshot is frozen now. The raw lab data stays on
     * the Test (the report reads it via the Report→Test proxy). Atomic. The test's
     * "reported" state is now derived from this report's existence (no status to
     * advance). The plan is applied later in the report editor (its
     * subscription_snapshot is captured there).
     */
    public static function createReportFromTest(Test $test): Report
    {
        return DB::transaction(function () use ($test) {
            $pet = $test->pet;
            // The report freezes/grounds notes AS OF the test's report date
            // (falling back to the collection date when no report date is set).
            $asOf = $test->report_date ?? $test->collected_at;
            $deterministic = [
                'species_richness' => $test->species_richness,
                'dysbiosis_score' => $test->dysbiosis_score,
                'microbiome_classification' => $test->microbiome_classification,
            ];
            $genError = null;
            $interp = self::interpretationColumns($test->phylum_data ?? [], $test->diversity_score, $pet, $asOf, $genError, $deterministic);
            $selection = self::productSelection($test->phylum_data ?? [], $test->diversity_score, $test->microbiome_classification);

            // Phase 2: grade the generation (observe-only; logged + returned).
            // Phase 3: persist the verdict — needs_review is deterministic-only.
            $verdict = self::gradeAndLog($interp, [
                'phylum_totals' => $test->phylum_data ?? [],
                'diversity_score' => $test->diversity_score,
                'species_richness' => $test->species_richness,
                'dysbiosis_score' => $test->dysbiosis_score,
                'microbiome_classification' => $test->microbiome_classification,
            ], $selection, $genError, ['path' => 'generate_from_test', 'test_id' => $test->getKey()]);

            $report = Report::create(array_merge($interp, [
                'client_id' => $test->client_id ?? $pet?->client_id,
                'pet_id' => $test->pet_id,
                'test_id' => $test->id,
                'status' => 'draft',
                'plan_id' => $selection['plan_id'],
                'pet_snapshot' => Report::buildPetSnapshot($pet, $asOf),
                'needs_review' => $verdict['needs_review'],
                'review_flags' => self::reviewFlagsFromVerdict($verdict),
            ]));

            if (! empty($selection['catalog_product_ids'])) {
                $sync = [];
                foreach ($selection['catalog_product_ids'] as $position => $id) {
                    $sync[$id] = ['position' => $position];
                }
                $report->catalogProducts()->sync($sync);
            }

            return $report;
        });
    }
}
