<?php

namespace App\Support;

/**
 * Phase 2 — a PURE, read-only grader for report AI output.
 *
 * Given the AI-generated interpretation columns plus the deterministic ground
 * truth computed from the lab data, it returns a structured verdict: a list of
 * issues, each tagged with a tier and severity. It has NO side effects (no
 * logging, no persistence, no model calls) — the caller logs/persists the
 * verdict. Generation behaviour is unchanged; this only observes.
 *
 * DISCIPLINE (the whole point of this phase):
 *  - DETERMINISTIC checks have effectively zero false positives. They are the
 *    only issues that set `needs_review` and therefore the only ones Phase 3 will
 *    surface to admins.
 *  - HEURISTIC checks are false-positive-prone (number parsing from prose, taxa
 *    name guessing, banned-phrase substrings). They are recorded in the verdict
 *    (tier=heuristic) and logged by the caller, but they NEVER set `needs_review`
 *    and will NOT be surfaced as admin-visible flags until tuned against real
 *    generation data. They are log-only for now.
 *
 * Verdict shape:
 *   [
 *     'issues' => [
 *       ['code' => string, 'severity' => 'error'|'warning'|'info',
 *        'tier' => 'deterministic'|'heuristic', 'detail' => string],
 *       ...
 *     ],
 *     'counts' => ['error' => int, 'warning' => int, 'info' => int],
 *     'deterministic_count' => int,
 *     'heuristic_count' => int,
 *     'needs_review' => bool,   // true iff >= 1 deterministic issue
 *   ]
 */
class ReportQualityValidator
{
    public const TIER_DETERMINISTIC = 'deterministic';

    public const TIER_HEURISTIC = 'heuristic';

    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    /**
     * The acceptable score_* values. The score_* columns are now computed
     * DETERMINISTICALLY (HealthInsightRules) rather than written by the AI, so the
     * per-insight band labels ('Target', 'Disrupted', 'Leaky Gut', …) are valid too;
     * the legacy AI enum values are kept so pre-Stage-2 reports still validate. The
     * enum check therefore only ever fires on a genuinely bad value (an out-of-set
     * manual override or an empty column). Union built from the rules config so the
     * two can't drift.
     */
    public const LEGACY_SCORES = ['Very High', 'High', 'Medium', 'Low'];

    public static function validScores(): array
    {
        return array_values(array_unique(array_merge(
            self::LEGACY_SCORES,
            HealthInsightRules::allBandLabels(),
        )));
    }

    /**
     * Heuristic tolerances — deliberately generous (precision over recall) since
     * these will eventually go admin-visible. Tunable here while we calibrate.
     *
     *  - PHYLUM_TOLERANCE_PCT: a stated percentage may differ from the computed
     *    figure by up to this many percentage points before it counts as a
     *    contradiction. 2.0 absorbs ordinary rounding ("40.0%" -> "40%") and soft
     *    phrasing ("around 40%") while still catching a clearly wrong number
     *    (e.g. stated 55% vs computed 40%).
     *  - DIVERSITY_TOLERANCE: same idea for the Shannon score (absolute units).
     */
    public const PHYLUM_TOLERANCE_PCT = 2.0;

    public const DIVERSITY_TOLERANCE = 0.3;

    /** Master + per-check toggles for the heuristic tier (flip while tuning). */
    public const HEURISTICS_ENABLED = true;

    public const ENABLE_NUMBER_CONTRADICTION = true;

    public const ENABLE_UNKNOWN_TAXON = true;

    public const ENABLE_BANNED_PHRASE = true;

    /** Diagnosis/cure language the prompt forbids; substring match, lower-cased. */
    public const BANNED_SUBSTRINGS = ['diagnos', 'cure', 'prescrib', 'guarantee'];

    /**
     * Microbial-name suffixes used to spot taxa-like tokens in free prose. Kept
     * specific so common pet names don't match (e.g. "Bella"); generic endings
     * like -ella / -ium are deliberately excluded to limit false positives and
     * avoid pulling a pet's name into the verdict.
     */
    public const TAXON_SUFFIXES = [
        'bacteria', 'bacter', 'coccus', 'bacillus', 'monas', 'etes', 'ota',
        'phila', 'philus', 'spira', 'vibrio', 'mycetes', 'plasma',
    ];

    /** The free-text interpretation fields the AI writes. */
    public const TEXT_FIELDS = [
        'ai_summary',
        'ai_bacteroidetes_interpretation',
        'ai_firmicutes_interpretation',
        'ai_fusobacteria_interpretation',
        'ai_proteobacteria_interpretation',
        'ai_diversity_interpretation',
        'vet_summary',
        'goal',
        'recommended_actions',
    ];

    /** The structured score fields (must be one of VALID_SCORES). */
    public const SCORE_FIELDS = [
        'score_gut_wall',
        'score_skin_allergy',
        'score_behaviour_mood',
        'score_gut_barrier',
        'score_gas_digestive',
        'score_stress_resilience',
    ];

    /** Maps each per-phylum prose field to the phylum_totals key it should state. */
    public const PHYLUM_FIELD_MAP = [
        'ai_bacteroidetes_interpretation' => 'Bacteroidetes',
        'ai_firmicutes_interpretation' => 'Firmicutes',
        'ai_fusobacteria_interpretation' => 'Fusobacteria',
        'ai_proteobacteria_interpretation' => 'Proteobacteria',
    ];

    /**
     * Grade one generation. See the class docblock for the context + verdict shapes.
     *
     * @param  array  $context  [
     *                          'interpretations' => array,   // ai_* + score_* columns
     *                          'phylum_totals' => array<string,float>,
     *                          'diversity_score' => float|null,
     *                          'species_richness' => int|null,
     *                          'dysbiosis_score' => float|null,
     *                          'microbiome_classification' => string|null,
     *                          'species' => array<int,string>,   // taxa ground truth (not retained today; defaults [])
     *                          'triggered' => array<int,string>,
     *                          'plan_id' => int|null,
     *                          'generation_error' => 'api_failed'|'json_parse_failed'|null,
     *                          ]
     */
    public static function validate(array $context): array
    {
        $interp = $context['interpretations'] ?? [];
        $generationError = $context['generation_error'] ?? null;
        $issues = [];

        // ───────────────────────── DETERMINISTIC ─────────────────────────

        // 1/2. Generation errored — surface the reason OpenAiService caught.
        if ($generationError === 'api_failed') {
            $issues[] = self::issue('generation_failed', self::SEVERITY_ERROR, self::TIER_DETERMINISTIC, 'AI call errored (transport/API/no key).');
        } elseif ($generationError === 'json_parse_failed') {
            $issues[] = self::issue('json_parse_failed', self::SEVERITY_ERROR, self::TIER_DETERMINISTIC, 'AI response could not be JSON-decoded.');
        }

        // 3. Empty output — only meaningful when there was no explicit error
        //    (i.e. the model returned a structurally-fine but blank result).
        $allEmpty = self::allInterpretationsEmpty($interp);
        if ($generationError === null && $allEmpty) {
            $issues[] = self::issue('empty_output', self::SEVERITY_ERROR, self::TIER_DETERMINISTIC, 'All interpretation fields are empty.');
        }

        // 4. Score enum — only when there is content to grade (a total failure is
        //    already covered above; don't emit six redundant enum errors).
        if (! $allEmpty) {
            foreach (self::SCORE_FIELDS as $field) {
                $value = trim((string) ($interp[$field] ?? ''));
                if (! in_array($value, self::validScores(), true)) {
                    $shown = $value === '' ? '(empty)' : self::clip($value, 40);
                    $issues[] = self::issue('bad_score_enum', self::SEVERITY_WARNING, self::TIER_DETERMINISTIC, "{$field} = {$shown}");
                }
            }
        }

        // 5. Triggers fired but no plan matched.
        $triggered = $context['triggered'] ?? [];
        $planId = $context['plan_id'] ?? null;
        if (! empty($triggered) && $planId === null) {
            $issues[] = self::issue('plan_unmatched', self::SEVERITY_WARNING, self::TIER_DETERMINISTIC, 'Triggers fired ('.count($triggered).') but no plan matched.');
        }

        // 6. Unwell-pet plan safety nets. Two mutually-exclusive cases, both soft
        //    (warning) but deterministic so they surface in the "needs review" queue:
        //      • toggle OFF / no fallback → no plan at all: the original unwell_no_plan
        //        flag ("choose a plan manually").
        //      • toggle ON → the pet was AUTO-assigned the fallback (Maintain &
        //        Protect) because no specific trigger fired: a soft
        //        auto_assigned_maintenance "please confirm" flag — there IS a plan,
        //        but the borderline case is worth an eyeball.
        //    Distinguished by the plan-selection reason_code (fallback_unwell).
        $classification = $context['microbiome_classification'] ?? null;
        $reasonCode = $context['plan_reason_code'] ?? null;
        if (ReportContent::isUnwellClassification($classification)) {
            if ($planId === null) {
                $issues[] = self::issue('unwell_no_plan', self::SEVERITY_WARNING, self::TIER_DETERMINISTIC, "Classified {$classification} but no plan matched — needs manual plan selection.");
            } elseif ($reasonCode === 'fallback_unwell') {
                $issues[] = self::issue('auto_assigned_maintenance', self::SEVERITY_WARNING, self::TIER_DETERMINISTIC, "Classified {$classification} and auto-assigned the fallback plan (no specific trigger fired) — confirm this is appropriate.");
            }
        }

        // 7. RETIRED — panel_contradiction. This flag fired when the classification
        //    was "Imbalanced & Depleted" while the diversity display band read "High".
        //    An audit proved it a false positive BY CONSTRUCTION: whenever it fired,
        //    the "Depleted" verdict came from the RICHNESS arm (richness < 400), never
        //    diversity (a High band means diversity > 2.5, which rules out the < 1.9
        //    diversity arm). So it only ever detected "high Shannon diversity + low
        //    species richness" — two DIFFERENT, legitimately co-existing metrics
        //    (Shannon = species count + evenness; richness = raw distinct-species
        //    count), not a contradiction. It flagged a valid biological state on a
        //    systematic slice of low-richness pets and cluttered the review queue,
        //    violating the deterministic tier's "effectively zero false positives"
        //    contract. Removed. The apparent mismatch is now reconciled in the report
        //    COPY (see OpenAiService::buildInterpretationsPrompt). classify() and
        //    diversityBand() are correct and are deliberately left unchanged.

        // 8. Microbe BAND contradiction (DETERMINISTIC — exact arithmetic, drives
        //    needs_review). The band a value sits in (low / within / high) is now
        //    computed in code; if the prose ASSERTS a different band (e.g. calls a
        //    low value "within the normal range"), the report tells the customer
        //    something the arithmetic contradicts — flag it. This is the
        //    Fusobacteria-2.97%-called-"normal" bug, caught even if the AI slips.
        if (! $allEmpty) {
            $issues = array_merge($issues, self::checkBandContradictions($interp, $context));
        }

        // 9. Unknown-taxon guardrail (DETERMINISTIC — drives needs_review). The
        //    safety net for the relaxed prompt lock: the AI may name ONLY the
        //    phyla + the specific taxa it was handed (this pet's top_taxa). A
        //    taxa-like token in the prose that is NOT in that whitelist is treated
        //    as an invented/hallucinated organism and flags the report for review.
        //    Runs in the deterministic tier so it fires regardless of the heuristic
        //    master toggle.
        if (self::ENABLE_UNKNOWN_TAXON && ! $allEmpty) {
            $issues = array_merge($issues, self::checkUnknownTaxa($interp, $context));
        }

        // ───────────────────────── HEURISTIC (log-only) ─────────────────────────
        // Skipped entirely on empty output (nothing to scan).
        if (self::HEURISTICS_ENABLED && ! $allEmpty) {
            if (self::ENABLE_NUMBER_CONTRADICTION) {
                $issues = array_merge($issues, self::checkNumberContradictions($interp, $context));
            }
            if (self::ENABLE_BANNED_PHRASE) {
                $issues = array_merge($issues, self::checkBannedPhrases($interp));
            }
        }

        return self::summarise($issues);
    }

    // ───────────────────────── heuristic checks ─────────────────────────

    /**
     * Present-tense claim phrases used by the deterministic band check. Kept to
     * CURRENT-STATE assertions ("is within", "is low") so a forward-looking goal
     * sentence ("bring it back within the typical range") does NOT false-positive.
     */
    public const BAND_NORMAL_CLAIMS = [
        'is within', 'are within', 'sits within', 'remains within', 'well within',
        'is in the normal', 'are in the normal', 'is in the typical', 'are in the typical',
        'is in the healthy', 'are in the healthy', 'is normal', 'are normal',
        'looks normal', 'appears normal', 'at a normal level', 'at a healthy level',
        'at normal levels', 'at healthy levels',
    ];

    public const BAND_LOW_CLAIMS = [
        'is low', 'are low', 'a low level', 'low levels', 'too low', 'on the low side',
        'is depleted', 'are depleted', 'is deficient', 'are deficient',
        'is below', 'are below', 'falls below', 'sits below',
    ];

    public const BAND_HIGH_CLAIMS = [
        'is high', 'are high', 'a high level', 'high levels', 'too high', 'on the high side',
        'is elevated', 'are elevated', 'elevated levels', 'overgrowth', 'in excess',
        'is above', 'are above', 'rises above', 'sits above',
    ];

    /**
     * DETERMINISTIC band-vs-prose check. For each per-phylum interpretation, the
     * value's band (low/within/high) is computed in code (ReportContent), then the
     * prose is scanned for a present-tense claim that contradicts it:
     *   low   → flagged if prose claims normal/within-range OR high
     *   high  → flagged if prose claims normal/within-range OR low
     *   within→ flagged if prose claims low OR high
     * High-precision (current-state phrases only) so it can drive needs_review.
     */
    protected static function checkBandContradictions(array $interp, array $context): array
    {
        $issues = [];
        $phylumTotals = $context['phylum_totals'] ?? [];

        foreach (self::PHYLUM_FIELD_MAP as $field => $phylum) {
            if (! array_key_exists($phylum, $phylumTotals)) {
                continue;
            }
            $verdict = ReportContent::phylumBandVerdict($phylum, (float) $phylumTotals[$phylum]);
            if ($verdict === null) {
                continue; // no defined band for this phylum
            }

            $prose = strtolower((string) ($interp[$field] ?? ''));
            if ($prose === '') {
                continue;
            }

            // Which claim sets contradict THIS computed band.
            $contradicting = match ($verdict['band']) {
                'low' => [...self::BAND_NORMAL_CLAIMS, ...self::BAND_HIGH_CLAIMS],
                'high' => [...self::BAND_NORMAL_CLAIMS, ...self::BAND_LOW_CLAIMS],
                default => [...self::BAND_LOW_CLAIMS, ...self::BAND_HIGH_CLAIMS], // within
            };

            $stated = null;
            foreach ($contradicting as $phrase) {
                if (str_contains($prose, $phrase)) {
                    $stated = $phrase;
                    break;
                }
            }
            if ($stated === null) {
                continue;
            }

            $computed = $verdict['band'] === 'within'
                ? 'within the typical range'
                : $verdict['band'].' relative to the typical range';

            $issues[] = self::issue(
                'band_contradiction',
                self::SEVERITY_WARNING,
                self::TIER_DETERMINISTIC,
                sprintf(
                    'The report describes %s as "%s" but its level %s%% is %s — please review.',
                    $phylum,
                    $stated,
                    self::num((float) $phylumTotals[$phylum]),
                    $computed,
                ),
            );
        }

        return $issues;
    }

    /**
     * Compare percentages stated in the per-phylum prose, and the score stated in
     * the diversity prose, against the computed figures. High-precision: only a
     * number that clearly contradicts (beyond tolerance) is recorded, and only
     * when the prose actually states a figure to check.
     */
    protected static function checkNumberContradictions(array $interp, array $context): array
    {
        $issues = [];
        $phylumTotals = $context['phylum_totals'] ?? [];

        foreach (self::PHYLUM_FIELD_MAP as $field => $phylum) {
            if (! array_key_exists($phylum, $phylumTotals)) {
                continue; // no ground truth for this phylum
            }
            $expected = (float) $phylumTotals[$phylum];
            $stated = self::extractPercentages((string) ($interp[$field] ?? ''));
            if ($stated === []) {
                continue; // prose stated no percentage — nothing to contradict
            }
            $nearest = self::nearest($stated, $expected);
            if (abs($nearest - $expected) > self::PHYLUM_TOLERANCE_PCT) {
                $issues[] = self::issue(
                    'number_contradiction',
                    self::SEVERITY_INFO,
                    self::TIER_HEURISTIC,
                    sprintf('%s: stated %s%% vs computed %s%%', $field, self::num($nearest), self::num($expected)),
                );
            }
        }

        // Diversity (Shannon) score.
        $diversity = $context['diversity_score'] ?? null;
        if ($diversity !== null) {
            $expected = (float) $diversity;
            $stated = self::extractDiversityCandidates((string) ($interp['ai_diversity_interpretation'] ?? ''));
            if ($stated !== []) {
                $nearest = self::nearest($stated, $expected);
                if (abs($nearest - $expected) > self::DIVERSITY_TOLERANCE) {
                    $issues[] = self::issue(
                        'number_contradiction',
                        self::SEVERITY_INFO,
                        self::TIER_HEURISTIC,
                        sprintf('ai_diversity_interpretation: stated %s vs computed %s', self::num($nearest), self::num($expected)),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * The prose fields where the AI may now name specific bacteria. Scanned by the
     * unknown-taxon guardrail for organisms outside this pet's whitelist.
     */
    public const TAXON_SCAN_FIELDS = [
        'ai_summary',
        'vet_summary',
        'recommended_actions',
        'ai_bacteroidetes_interpretation',
        'ai_firmicutes_interpretation',
        'ai_fusobacteria_interpretation',
        'ai_proteobacteria_interpretation',
        'ai_diversity_interpretation',
    ];

    /**
     * DETERMINISTIC guardrail for the relaxed prompt lock. Builds the pet's
     * allowed-taxa whitelist (phylum keys + the names of the top_taxa the AI was
     * handed, passed in via context['species']), then scans the prose fields for
     * taxa-like tokens (capitalised words with a microbial suffix). Any such token
     * NOT in the whitelist is an organism the AI was never given — i.e. invented —
     * so it flags the report for review.
     *
     * The suffix-based detector (TAXON_SUFFIXES) is deliberately narrow so ordinary
     * pet names (e.g. "Bella", generic -ella/-ium endings excluded) never match.
     */
    protected static function checkUnknownTaxa(array $interp, array $context): array
    {
        $known = [];
        foreach (array_keys($context['phylum_totals'] ?? []) as $phylum) {
            $known[strtolower((string) $phylum)] = true;
        }
        // context['species'] now carries this pet's retained top_taxa NAMES (genus
        // rollups + species). Whitelist each word so a multi-word name like
        // "Fusobacterium perfoetens" clears on either token.
        foreach ($context['species'] ?? [] as $species) {
            foreach (preg_split('/\s+/', strtolower((string) $species)) as $word) {
                if ($word !== '') {
                    $known[$word] = true;
                }
            }
        }

        $suffixAlt = implode('|', self::TAXON_SUFFIXES);
        $pattern = '/\b([A-Z][a-z]+(?:'.$suffixAlt.'))\b/';

        $candidates = [];
        foreach (self::TAXON_SCAN_FIELDS as $field) {
            if (preg_match_all($pattern, (string) ($interp[$field] ?? ''), $m)) {
                foreach ($m[1] as $token) {
                    if (! isset($known[strtolower($token)])) {
                        $candidates[$token] = true;
                    }
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $names = array_keys($candidates);
        $detail = count($names) === 1
            ? "The report names \"{$names[0]}\", which was not in this pet's lab data — please verify it wasn't invented."
            : 'The report names organisms not in this pet\'s lab data: '.implode(', ', $names).' — please verify they were not invented.';

        return [self::issue(
            'unknown_taxon',
            self::SEVERITY_WARNING,
            self::TIER_DETERMINISTIC,
            $detail,
        )];
    }

    /** Scan all text fields for diagnosis/cure language that slipped the prompt. */
    protected static function checkBannedPhrases(array $interp): array
    {
        $hits = [];
        foreach (self::TEXT_FIELDS as $field) {
            $haystack = strtolower((string) ($interp[$field] ?? ''));
            foreach (self::BANNED_SUBSTRINGS as $needle) {
                if ($haystack !== '' && str_contains($haystack, $needle)) {
                    $hits[$needle] = true;
                }
            }
        }

        if ($hits === []) {
            return [];
        }

        return [self::issue(
            'banned_phrase',
            self::SEVERITY_INFO,
            self::TIER_HEURISTIC,
            'matched: '.implode(', ', array_keys($hits)),
        )];
    }

    // ───────────────────────── helpers ─────────────────────────

    protected static function allInterpretationsEmpty(array $interp): bool
    {
        // AI TEXT only. The score_* columns are now always populated (computed
        // deterministically), so including them would mask a genuinely empty AI
        // response — "empty output" means the model produced no prose.
        foreach (self::TEXT_FIELDS as $field) {
            if (trim((string) ($interp[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Numbers explicitly written as a percentage, e.g. "40%", "39.5 %". */
    protected static function extractPercentages(string $text): array
    {
        if (! preg_match_all('/(\d+(?:\.\d+)?)\s*%/', $text, $m)) {
            return [];
        }

        return array_map('floatval', $m[1]);
    }

    /**
     * Plausible Shannon-score candidates: any decimal (has a fractional part) in
     * [0,10], plus bare integers in [0,6]. This admits "2.4" / "a score of 3.0"
     * while excluding things like "8 to 12 weeks" (integers > 6) and percentages.
     */
    protected static function extractDiversityCandidates(string $text): array
    {
        // Strip percentages first so "40%" can't masquerade as a score.
        $text = preg_replace('/\d+(?:\.\d+)?\s*%/', ' ', $text);

        if (! preg_match_all('/\d+(?:\.\d+)?/', (string) $text, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[0] as $raw) {
            $value = (float) $raw;
            $isDecimal = str_contains($raw, '.');
            if ($isDecimal && $value >= 0 && $value <= 10) {
                $out[] = $value;
            } elseif (! $isDecimal && $value >= 0 && $value <= 6) {
                $out[] = $value;
            }
        }

        return $out;
    }

    protected static function nearest(array $values, float $target): float
    {
        $best = $values[0];
        foreach ($values as $v) {
            if (abs($v - $target) < abs($best - $target)) {
                $best = $v;
            }
        }

        return (float) $best;
    }

    protected static function issue(string $code, string $severity, string $tier, string $detail): array
    {
        return ['code' => $code, 'severity' => $severity, 'tier' => $tier, 'detail' => $detail];
    }

    protected static function summarise(array $issues): array
    {
        $counts = [self::SEVERITY_ERROR => 0, self::SEVERITY_WARNING => 0, self::SEVERITY_INFO => 0];
        $deterministic = 0;
        $heuristic = 0;

        foreach ($issues as $issue) {
            $counts[$issue['severity']]++;
            if ($issue['tier'] === self::TIER_DETERMINISTIC) {
                $deterministic++;
            } else {
                $heuristic++;
            }
        }

        return [
            'issues' => $issues,
            'counts' => $counts,
            'deterministic_count' => $deterministic,
            'heuristic_count' => $heuristic,
            // Only deterministic issues drive the (future) admin-visible flag.
            'needs_review' => $deterministic > 0,
        ];
    }

    protected static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    protected static function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
