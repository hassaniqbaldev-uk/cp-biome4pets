<?php

namespace App\Support;

use App\Filament\Resources\ReportResource;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Services\OpenAiService;

/**
 * Materialises a retest's FORCED plan (chosen by the retest override) into the report's
 * report_steps / report_step_products + subscription_snapshot, so the overridden plan
 * renders COMPLETE in the normal retest flow — without the admin having to click the
 * manual "Apply plan" action (which, for a retest, they otherwise would not, since the
 * plan is forced programmatically).
 *
 * It instantiates through the SINGLE variant-aware seam (PlanInstantiation::build),
 * exactly like the "Apply plan" action, so variant resolution always runs and a flagged
 * pet can never be stranded on base products. It also generates the SAME per-pet plan
 * copy a first-test report gets — reusing the existing path (OpenAiService::generatePlanCopy
 * grounded by the plan scaffold, then the ReportResource::validatePlanCopy guardrail that
 * locks every factual field) — so the forced retest plan reads as finished. No new prompt
 * and no bypass of the validator: one plan-copy AI call per firing retest, matching a
 * normal report, degrading to placeholder copy (never throws) on no-key/failure. The
 * report still carries the deterministic retest_review flag, so staff review before publish.
 *
 * Lives OUTSIDE ReportGeneration on purpose: the single-instantiation-seam lock
 * (PlanVariantPathConsistencyTest) keeps ReportGeneration free of inline report-step /
 * subscription_snapshot writes. ReportGeneration delegates here; here goes through
 * PlanInstantiation. The persisted shape mirrors EditReport::persistPlanSteps exactly.
 */
class RetestPlanMaterializer
{
    /**
     * Persist $plan into $report (replacing any existing steps) and return the
     * instantiation (form-shape steps + subscription_snapshot) so an interactive caller
     * can sync its form state to the just-written DB rows. Idempotent: it rebuilds from
     * the plan each call.
     *
     * @return array{steps: array<int,array>, subscription_snapshot: array<string,mixed>, variant: ?string, needs_review_reason: ?string}
     */
    public static function materialise(Report $report, Plan $plan): array
    {
        // Same plan-copy path a first-test report uses: build the plan scaffold + this
        // pet's findings, generate the copy, then run it through the factual-locking
        // guardrail (validatePlanCopy) before applying. A no-key / failed call yields
        // empty copy → PlanInstantiation falls back to placeholders, as before this step.
        $scaffold = $plan->toScaffold($report->pet?->name);
        $modelOutput = app(OpenAiService::class)->generatePlanCopy(
            PetFindings::fromReport($report),
            $scaffold,
            $report->getKey(),
        );
        $copy = ReportResource::validatePlanCopy($modelOutput, $scaffold);

        // Validated copy overlay applied through the one variant-aware seam (so
        // swaps/dosage/checkout link always resolve).
        $instantiation = PlanInstantiation::build($plan, $report->pet, $copy);

        // Rebuild report_steps / report_step_products (DB cascade clears old products).
        ReportStep::where('report_id', $report->getKey())->delete();

        foreach (array_values($instantiation['steps']) as $stepIndex => $stepData) {
            $type = $stepData['type'] ?? 'product';

            $step = $report->steps()->create([
                'title' => $stepData['title'] ?? '',
                'description' => $stepData['description'] ?? null,
                'type' => $type,
                'stage_label' => $stepData['stage_label'] ?? null,
                'body' => $type === 'prose' ? ($stepData['body'] ?? null) : null,
                'tip' => $type === 'prose' ? ($stepData['tip'] ?? null) : null,
                'position' => $stepIndex,
            ]);

            if ($type !== 'product') {
                continue;
            }

            foreach (array_values($stepData['products'] ?? []) as $productIndex => $productData) {
                if (empty($productData['catalog_product_id'])) {
                    continue;
                }

                $step->products()->create([
                    'catalog_product_id' => $productData['catalog_product_id'],
                    'duration' => $productData['duration'] ?? null,
                    'quantity' => $productData['quantity'] ?? null,
                    'dose' => $productData['dose'] ?? null,
                    'inclusion' => $productData['inclusion'] ?? 'included',
                    'how_it_helps' => $productData['how_it_helps'] ?? null,
                    'position' => $productIndex,
                ]);
            }
        }

        // Freeze the subscribe-panel snapshot + the generated plan intro. Prices stay
        // hidden on retests regardless: the Report snapshot accessor nulls price/url while
        // hide_commerce is set (the retest default), so this never exposes a buy price.
        // plan_intro is only written when real copy was generated, so a no-key/failed call
        // leaves any existing intro untouched (blade handles a null intro gracefully).
        $update = ['subscription_snapshot' => $instantiation['subscription_snapshot']];
        if (($copy['intro'] ?? '') !== '') {
            $update['plan_intro'] = $copy['intro'];
        }
        $report->update($update);

        return $instantiation;
    }
}
