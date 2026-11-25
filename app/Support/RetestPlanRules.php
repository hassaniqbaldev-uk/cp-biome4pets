<?php

namespace App\Support;

/**
 * Retest-only plan rules: deterministic rules that OVERRIDE the normal first-test
 * plan selection when a report is a retest. Two kinds of rule:
 *   - a 'delta' rule (the FMT rule from step 1a) read from the frozen retest_comparison
 *     snapshot (original-vs-retest diversity/richness falls); and
 *   - 'threshold' rules read from THIS retest's own CSV percentages via TaxonReader
 *     (step 2a) — a named taxon at a given rank exceeding a percent threshold.
 *
 * This class only DECIDES which rule wins (declarative RETEST_RULES + the evaluator
 * selectRetestRule); resolving the winning rule's plan key to a Plan and wiring it onto
 * a report is ReportGeneration::retestPlanOverride(). Thresholds live here as data,
 * mirroring how ReportContent holds its specialist ranges. First tests never reach any
 * of this — the override is gated on Report::isRetest().
 */
class RetestPlanRules
{
    /**
     * FMT retest rule thresholds, comparing the ORIGINAL test to THIS retest:
     *   - diversity (species-resolved Shannon) must have FALLEN by at least this much
     *     (absolute drop), and
     *   - species richness must have FALLEN by at least this FRACTION of the original.
     * BOTH must hold for the rule to fire.
     */
    public const FMT_RETEST_DIVERSITY_DROP = 0.5;

    public const FMT_RETEST_RICHNESS_DROP = 0.50;

    /**
     * The declarative retest rule set. Evaluated in full for every retest; when several
     * fire, the LOWEST 'priority' wins (1 = highest precedence, overrides all others).
     * This precedence order is the team-confirmable default:
     *   Spirochaetes → FMT retest → Escherichia/Shigella → pathogens → Enterococcus
     *   → Erysipelotrichaceae → Sutterella → Flavonifactor.
     *
     * Each rule carries:
     *   - key:      stable rule id; the override's recommendation_reason code is
     *               "retest_{key}" (e.g. 'retest_spirochaetes').
     *   - plan_key: the target plan, resolved by key at wiring time (never a hardcoded id).
     *   - priority: lower wins.
     *   - type:     'threshold' (TaxonReader %, operator 'gt') or 'delta' (the FMT rule).
     *   - taxa:     for 'threshold', the [rank, name] pairs to read; the rule fires when
     *               ANY of them exceeds 'threshold' (so 'pathogens' ORs three taxa). Names
     *               use TaxonReader handling (Escherichia/Shigella matching, the client's
     *               Flavonifactor → CSV Flavonifractor correction).
     *
     * @var array<int, array<string, mixed>>
     */
    public const RETEST_RULES = [
        [
            'key' => 'spirochaetes', 'plan_key' => 'retest-spirochaetes', 'priority' => 1,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 2.5,
            'taxa' => [['phylum', 'Spirochaetes']],
        ],
        [
            'key' => 'fmt_retest', 'plan_key' => 'rebuild-renew', 'priority' => 2,
            'type' => 'delta',
            // Explicit reason: kept as 'retest_fmt' (step 1a) rather than the derived
            // 'retest_fmt_retest', so the existing override reason code is unchanged.
            'reason' => 'retest_fmt',
        ],
        [
            'key' => 'escherichia_shigella', 'plan_key' => 'retest-amr-only', 'priority' => 3,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 2.0,
            'taxa' => [['genus', 'Escherichia/Shigella']],
        ],
        [
            'key' => 'pathogens', 'plan_key' => 'retest-amr-only', 'priority' => 4,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 2.0,
            'taxa' => [['genus', 'Campylobacter'], ['genus', 'Helicobacter'], ['species', 'Clostridium perfringens']],
        ],
        [
            'key' => 'enterococcus', 'plan_key' => 'reset-recover', 'priority' => 5,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 5.0,
            'taxa' => [['genus', 'Enterococcus']],
        ],
        [
            'key' => 'erysipelotrichaceae', 'plan_key' => 'retest-prebiotic-only', 'priority' => 6,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 2.5,
            'taxa' => [['family', 'Erysipelotrichaceae']],
        ],
        [
            'key' => 'sutterella', 'plan_key' => 'maintain-protect', 'priority' => 7,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 5.0,
            'taxa' => [['genus', 'Sutterella']],
        ],
        [
            'key' => 'flavonifactor', 'plan_key' => 'maintain-protect', 'priority' => 8,
            'type' => 'threshold', 'operator' => 'gt', 'threshold' => 2.0,
            'taxa' => [['genus', 'Flavonifactor']],
        ],
    ];

    /**
     * Select the winning retest rule, or null when none fires. Evaluates every rule:
     * threshold rules read $reader (this retest's CSV); the delta rule reads $comparison
     * (the frozen snapshot). Among those that fire, the lowest 'priority' wins.
     *
     * Both inputs are optional and independent: a null $reader simply means no threshold
     * rule can fire (e.g. the CSV is unavailable), and a null/non-array $comparison means
     * the delta rule cannot fire — neither is an error.
     *
     * @return array<string, mixed>|null  the winning rule definition (+ 'reason' code)
     */
    public static function selectRetestRule(?TaxonReader $reader, ?array $comparison): ?array
    {
        $fired = [];

        foreach (self::RETEST_RULES as $rule) {
            $fires = ($rule['type'] ?? null) === 'delta'
                ? (is_array($comparison) && self::fmtRetestFires($comparison))
                : ($reader !== null && self::thresholdFires($rule, $reader));

            if ($fires) {
                $fired[] = $rule;
            }
        }

        if ($fired === []) {
            return null;
        }

        usort($fired, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $winner = $fired[0];
        // Reason code: explicit 'reason' when set (the FMT rule keeps 'retest_fmt'),
        // otherwise derived as "retest_{key}" (e.g. 'retest_spirochaetes').
        $winner['reason'] ??= 'retest_'.$winner['key'];

        return $winner;
    }

    /**
     * Does a threshold rule fire against this retest's CSV? Fires when ANY of the rule's
     * taxa exceeds its threshold (operator 'gt'), read via TaxonReader so taxon-name
     * handling (combined names, the Flavonifactor spelling fix) is consistent with step 2a.
     *
     * @param  array<string, mixed>  $rule
     */
    private static function thresholdFires(array $rule, TaxonReader $reader): bool
    {
        $threshold = (float) ($rule['threshold'] ?? 0);

        foreach (($rule['taxa'] ?? []) as [$rank, $name]) {
            if ($reader->taxonPercent((string) $rank, (string) $name) > $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the FMT retest rule fire for this frozen comparison?
     *
     * Reads the portal's own overview figures (the same species-resolved Shannon and
     * richness the rest of the retest uses): comparison.overview.{original,retest}.
     * {diversity, species_richness} (see App\Support\RetestComparison::overview()).
     *
     * Guardrails — all return FALSE, never an error: a missing overview/original/retest,
     * any of the four figures missing/null, or an original richness of 0 (no divide).
     */
    public static function fmtRetestFires(array $comparison): bool
    {
        $original = $comparison['overview']['original'] ?? null;
        $retest = $comparison['overview']['retest'] ?? null;

        if (! is_array($original) || ! is_array($retest)) {
            return false;
        }

        $originalRichness = $original['species_richness'] ?? null;
        $retestRichness = $retest['species_richness'] ?? null;
        $originalDiversity = $original['diversity'] ?? null;
        $retestDiversity = $retest['diversity'] ?? null;

        // Any missing figure → not fired (never divide, never error).
        if ($originalRichness === null || $retestRichness === null
            || $originalDiversity === null || $retestDiversity === null) {
            return false;
        }

        $originalRichness = (float) $originalRichness;

        // Guardrail: original richness 0 (or negative) → cannot compute a fall, not fired.
        if ($originalRichness <= 0.0) {
            return false;
        }

        $richnessDrop = ($originalRichness - (float) $retestRichness) / $originalRichness;
        $diversityDrop = (float) $originalDiversity - (float) $retestDiversity;

        return $richnessDrop >= self::FMT_RETEST_RICHNESS_DROP
            && $diversityDrop >= self::FMT_RETEST_DIVERSITY_DROP;
    }
}
