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

        // Stage 2: the six health-insight score_* values are computed
        // DETERMINISTICALLY from the bacteria percentages (HealthInsightRules), not
        // written by the AI. Build the driver percentages from the raw phylum data +
        // the Stage-1 insight_taxa (genus %) carried in $deterministic, then band
        // them. This overrides whatever the AI used to return (it no longer does).
        $scores = HealthInsightRules::computeScores(
            ReportContent::insightTaxonPercentagesFrom($phylumData, $deterministic['insight_taxa'] ?? []),
        );

        return array_merge([
            'ai_summary' => $interp['summary'],
            'ai_bacteroidetes_interpretation' => $interp['bacteroidetes_interpretation'],
            'ai_firmicutes_interpretation' => $interp['firmicutes_interpretation'],
            'ai_fusobacteria_interpretation' => $interp['fusobacteria_interpretation'],
            'ai_proteobacteria_interpretation' => $interp['proteobacteria_interpretation'],
            'ai_diversity_interpretation' => $interp['diversity_interpretation'],
            // 'vet_summary' is the owner-facing DETAIL paragraph of the personal
            // summary (a misnomer — not vet-facing). See Report::$fillable / the prompt.
            'vet_summary' => $interp['vet_summary'],
            'goal' => $interp['goal'],
            'recommended_actions' => $interp['recommended_actions'],
        ], $scores);
    }

    /**
     * Whether the AI TEXT half of a generation came back empty — the signal that a
     * transient API failure produced no usable prose (so the caller keeps existing
     * content / warns). Deliberately ignores the score_* columns: those are now
     * ALWAYS present (computed deterministically), so counting them would mask a
     * genuinely empty AI response.
     */
    public static function aiTextIsEmpty(array $interpretations): bool
    {
        foreach (ReportQualityValidator::TEXT_FIELDS as $field) {
            if (trim((string) ($interpretations[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Flatten a top_taxa list to just its display names — the allowed-taxa
     * whitelist for the unknown-taxon guardrail. Tolerates absent/old data
     * (returns []) so pre-Stage-1 reports behave exactly as before.
     *
     * @param  array<int,array{name?:string}>  $topTaxa
     * @return array<int,string>
     */
    public static function taxaNames(array $topTaxa): array
    {
        return array_values(array_filter(array_map(
            fn ($t): string => is_array($t) ? (string) ($t['name'] ?? '') : '',
            $topTaxa,
        ), fn (string $n): bool => $n !== ''));
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

        $flags = [
            'detected_at' => now()->toIso8601String(),
            'issues' => $verdict['issues'],
        ];

        // Durable record of the auto-match outcome: if generation produced a
        // "no plan auto-matched" flag, remember WHICH one. This is the signal the
        // live recompute uses to distinguish a later MANUAL plan selection (which
        // becomes a Super-Admin sanity check) from a normal auto-match. Survives
        // the flag being rewritten as the admin selects/deselects a plan.
        if ($origin = self::planOriginFromIssues($verdict['issues'])) {
            $flags['plan_origin'] = $origin;
        }

        return $flags;
    }

    /** The generation-time "no plan auto-matched" code, or null. */
    protected static function planOriginFromIssues(array $issues): ?string
    {
        foreach ($issues as $issue) {
            if (in_array($issue['code'] ?? '', ['unwell_no_plan', 'plan_unmatched'], true)) {
                return $issue['code'];
            }
        }

        return null;
    }

    /** Review-flag codes whose condition an ADMIN EDIT can resolve (plan picked,
     *  score corrected), so they are re-derived live on save. Everything else
     *  (band/number/taxon/banned/generation/empty) depends on the AI prose or a
     *  transient generation event and is preserved — refreshed only by regenerate.
     *
     *  panel_contradiction is a RETIRED check (see ReportQualityValidator): listing
     *  it here means recomputeReviewState DROPS it from any report still carrying it
     *  (it's filtered out of $kept and nothing re-adds it), so a stale flag clears on
     *  the next re-save — no full regenerate needed — and needs_review recomputes
     *  from the remaining deterministic issues. */
    public const LIVE_REFRESH_CODES = [
        'bad_score_enum',
        'unwell_no_plan',
        'plan_unmatched',
        'manual_plan_review',
        'panel_contradiction',
    ];

    /**
     * Re-evaluate the edit-resolvable review flags against the report's CURRENT
     * state, so the "needs review" surfaces never go stale after an admin edit.
     * Fixes the reported bug: the "no plan selected" nag persisted after a plan was
     * chosen. Two flag families are refreshed; all other recorded issues are kept.
     *
     *   PLAN: a report whose results did NOT auto-match a plan (plan_origin marker)
     *     shows — depending on the CURRENT plan_id —
     *       • no plan selected  → the original "choose a plan" flag (correct here),
     *       • plan selected      → "manual plan selected, needs Super Admin review"
     *         (any plan chosen on a no-auto-match report is, by definition, manual).
     *     A report that auto-matched normally is never in this family → no flag.
     *
     *   SCORES: bad_score_enum is re-checked against the current score_* columns, so
     *     correcting a bad score in the editor clears it (and introducing one flags).
     *
     * needs_review is recomputed from the resulting deterministic issues, but a
     * report explicitly "Marked as reviewed" stays cleared while its issue set is
     * unchanged — only a real change (resolve/introduce/transition) re-surfaces it.
     */
    public static function recomputeReviewState(Report $report): void
    {
        $flags = is_array($report->review_flags) ? $report->review_flags : [];
        $issues = $flags['issues'] ?? [];
        $prevCodes = array_column($issues, 'code');
        sort($prevCodes);

        // Preserve every issue that isn't one of the live-refreshable families.
        $kept = array_values(array_filter(
            $issues,
            fn (array $i): bool => ! in_array($i['code'] ?? '', self::LIVE_REFRESH_CODES, true),
        ));

        // SCORES — re-derive bad_score_enum from the CURRENT score columns.
        foreach (ReportQualityValidator::SCORE_FIELDS as $field) {
            $value = trim((string) ($report->{$field} ?? ''));
            if (! in_array($value, ReportQualityValidator::validScores(), true)) {
                $shown = $value === '' ? '(empty)' : $value;
                $kept[] = self::issueRow('bad_score_enum', "{$field} = {$shown}");
            }
        }

        // PLAN — only for reports that did NOT auto-match a plan (have a plan_origin,
        // inferred from legacy issues if the marker predates this feature).
        $origin = $flags['plan_origin'] ?? self::planOriginFromIssues($issues)
            ?? (in_array('manual_plan_review', $prevCodes, true) ? 'unwell_no_plan' : null);

        if ($origin !== null) {
            $kept[] = $report->plan_id === null
                ? self::issueRow($origin, self::planFlagDetail($origin))
                : self::issueRow('manual_plan_review', self::planFlagDetail('manual_plan_review'));
        }

        // Rebuild. needs_review respects a prior "Mark as reviewed": keep the stored
        // value when the issue set is unchanged; otherwise recompute from current
        // deterministic issues.
        $newCodes = array_column($kept, 'code');
        sort($newCodes);
        $changed = $newCodes !== $prevCodes;

        $deterministic = count(array_filter(
            $kept,
            fn (array $i): bool => ($i['tier'] ?? '') === ReportQualityValidator::TIER_DETERMINISTIC,
        ));

        $newFlags = null;
        if ($kept !== []) {
            $newFlags = [
                'detected_at' => $flags['detected_at'] ?? now()->toIso8601String(),
                'issues' => $kept,
            ];
            if ($origin !== null) {
                $newFlags['plan_origin'] = $origin;
            }
        }

        $update = ['review_flags' => $newFlags];
        if ($changed) {
            $update['needs_review'] = $deterministic > 0;
        }

        $report->update($update);
    }

    /**
     * Review-flag code for the plan-variant combined-gap: a sensitive+large pet that
     * resolved to a single-axis variant or base because no dedicated 'sensitive_large'
     * variant exists. Deterministic warning — a human must confirm the link/dosage.
     */
    public const VARIANT_GAP_CODE = 'variant_combined_gap';

    /**
     * Merge the plan-variant combined-gap reason into a review_flags array, returning
     * the updated flags (or the input unchanged when there is no reason). Idempotent:
     * re-applying replaces the prior variant-gap issue rather than stacking it, so a
     * re-apply of the plan never duplicates the flag. needs_review is driven separately
     * by the caller / recomputeReviewState (the code is a deterministic-tier issue).
     */
    public static function withVariantReviewFlag(?array $flags, ?string $reason): ?array
    {
        if ($reason === null) {
            return $flags;
        }

        $flags = is_array($flags) ? $flags : [];
        $issues = $flags['issues'] ?? [];

        // Drop any existing variant-gap row first (idempotent re-apply).
        $issues = array_values(array_filter(
            $issues,
            fn (array $i): bool => ($i['code'] ?? '') !== self::VARIANT_GAP_CODE,
        ));
        $issues[] = self::issueRow(self::VARIANT_GAP_CODE, $reason);

        $flags['issues'] = $issues;
        $flags['detected_at'] = $flags['detected_at'] ?? now()->toIso8601String();

        return $flags;
    }

    /** A deterministic-warning issue row in the stored review_flags shape. */
    protected static function issueRow(string $code, string $detail): array
    {
        return [
            'code' => $code,
            'severity' => ReportQualityValidator::SEVERITY_WARNING,
            'tier' => ReportQualityValidator::TIER_DETERMINISTIC,
            'detail' => $detail,
        ];
    }

    /** Current-state detail text for a plan flag. */
    protected static function planFlagDetail(string $code): string
    {
        return match ($code) {
            'manual_plan_review' => 'A plan was selected manually after no plan auto-matched — a Super Admin should review the manual selection.',
            'plan_unmatched' => 'Product rules fired but no plan is selected — choose one manually before publishing.',
            default => 'The pet looks imbalanced but no plan is selected — choose one manually before publishing.',
        };
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
                // The pet's specific bacteria (Stage 1 retention). Fed to the prompt
                // as fixed facts and used as the validator's allowed-taxa whitelist.
                'top_taxa' => $test->csv_data['top_taxa'] ?? [],
                // Stage 2: genus % for the deterministic health-insight scores.
                'insight_taxa' => $test->csv_data['insight_taxa'] ?? [],
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
                // Allowed-taxa whitelist for the unknown-taxon guardrail.
                'species' => self::taxaNames($deterministic['top_taxa'] ?? []),
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

    /**
     * Re-run AI generation on an EXISTING report so it picks up generation fixes
     * (e.g. the deterministic band verdict + band_contradiction validator). Reuses
     * the SAME path as creation — interpretationColumns() (the OpenAiService call,
     * now band-aware) + gradeAndLog() (the validator) — and OVERWRITES the report's
     * stored AI content (ai_*, score_*) plus re-applies needs_review / review_flags.
     *
     * It does NOT touch the plan, products, subscription snapshot or status. It DOES
     * refresh the frozen pet_snapshot from the current pet (unconditionally, even if
     * the AI step fails — so a diet/name/breed edit made after generation is picked
     * up), while keeping the AI copy grounded in the linked Test's raw lab data AS OF
     * the original report date so the interpretation is consistent with how it was
     * first generated.
     *
     * SAFETY: if the AI call errors or returns an all-empty result, the existing
     * stored content is KEPT (never wiped by a transient API failure) and the
     * method reports the failure instead.
     *
     * @return array{ok:bool, needs_review:bool, reason:?string}
     */
    public static function regenerateReport(Report $report): array
    {
        $test = $report->test;
        if (! $test) {
            return ['ok' => false, 'needs_review' => (bool) $report->needs_review, 'reason' => 'no_linked_test'];
        }

        $phylumData = $test->phylum_data ?? [];
        if (empty($phylumData)) {
            return ['ok' => false, 'needs_review' => (bool) $report->needs_review, 'reason' => 'no_lab_data'];
        }

        $pet = $report->pet;
        // Ground notes AS OF the report's date, mirroring createReportFromTest.
        $asOf = $test->report_date ?? $test->collected_at;

        // Refresh the FROZEN pet snapshot from the current pet FIRST — before the AI
        // step, so it applies even if the AI call fails (the snapshot is deterministic
        // pet FACTS, not AI content, so refreshing it never wipes good prose). The
        // report reads pet fields (diet, name, breed, …) from this snapshot, not the
        // live pet, so a pet edited after generation — e.g. diet corrected to Kibble —
        // would otherwise never surface. Regenerate is the "rebuild from current data"
        // action: change the diet, regenerate, and the nutritionist diet-review
        // statement appears. Notes stay grounded AS OF the report date via $asOf.
        $report->update(['pet_snapshot' => Report::buildPetSnapshot($pet, $asOf)]);

        $deterministic = [
            'species_richness' => $test->species_richness,
            'dysbiosis_score' => $test->dysbiosis_score,
            'microbiome_classification' => $test->microbiome_classification,
            'top_taxa' => $test->csv_data['top_taxa'] ?? [],
            // Stage 2: genus % for the deterministic health-insight scores.
            'insight_taxa' => $test->csv_data['insight_taxa'] ?? [],
        ];

        $genError = null;
        $interp = self::interpretationColumns($phylumData, $test->diversity_score, $pet, $asOf, $genError, $deterministic);

        // Never overwrite good content with a transient failure / empty result.
        // Judge emptiness on the AI TEXT only — score_* are always populated now.
        $allEmpty = self::aiTextIsEmpty($interp);
        if ($genError !== null || $allEmpty) {
            return ['ok' => false, 'needs_review' => (bool) $report->needs_review, 'reason' => $genError ?? 'empty_output'];
        }

        // Re-grade with the SAME validator (band_contradiction etc. re-apply).
        // Plan/triggers are unchanged here, so reuse the report's existing selection.
        $selection = ['triggered' => [], 'plan_id' => $report->plan_id];
        $verdict = self::gradeAndLog($interp, [
            'phylum_totals' => $phylumData,
            'diversity_score' => $test->diversity_score,
            'species_richness' => $test->species_richness,
            'dysbiosis_score' => $test->dysbiosis_score,
            'microbiome_classification' => $test->microbiome_classification,
            'species' => self::taxaNames($deterministic['top_taxa'] ?? []),
        ], $selection, $genError, ['path' => 'bulk_regenerate', 'report_id' => $report->getKey()]);

        $report->update(array_merge($interp, [
            'needs_review' => $verdict['needs_review'],
            'review_flags' => self::reviewFlagsFromVerdict($verdict),
        ]));

        return ['ok' => true, 'needs_review' => $verdict['needs_review'], 'reason' => null];
    }
}
