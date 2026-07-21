<?php

namespace App\Support;

use App\Models\Setting;

/**
 * STAGE 2 of the deterministic health-insights rework: the RULES ENGINE.
 *
 * Each of the six microbiome-driven health insights is computed DETERMINISTICALLY
 * from a single bacteria percentage (made reliably available in Stage 1 — see
 * ReportContent::insightTaxonPercentages). For a given percentage we pick the
 * matching BAND and attach the client's FIXED comment text for that band. These
 * values REPLACE the former AI-generated score_* fields.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ALL thresholds, band definitions and comment text live in the ONE config
 *  constant below (HEALTH_INSIGHT_RULES) plus TARGET_TOLERANCE. Nothing about the
 *  banding logic is hard-coded in the methods — adjust the numbers/text in the
 *  config and the engine follows. Every value that is my current ASSUMPTION
 *  (pending the client's final confirmation) is tagged inline with
 *      // ASSUMPTION - pending client confirmation
 *  and every band still missing distinct client wording is tagged
 *      // NEEDS medium comment from client
 *  so both are trivially greppable the moment she replies.
 *
 *  This stage does NOT touch the gauges/legend/colours (Stage 3). It only computes
 *  the band label + comment + good/bad direction and stores enough for Stage 3 to
 *  colour each insight correctly.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class HealthInsightRules
{
    /**
     * Width of the "Target" window on EACH side of a point target. The client has
     * CONFIRMED that targets are RANGES, not exact figures (e.g. 24.8% and 25.2%
     * both count as on-target for a 25% target) — so this window mechanism is
     * correct. The exact WIDTH may still be refined (the client offered to review
     * it); it is kept as one clearly-labelled adjustable constant. Set to 0.0 for
     * exact-point targets, or widen for a broader on-target range.
     *
     * Only insights whose config uses the 'target' predicate consult this: the two
     * point-target insights Skin & Allergy (Bacteroidetes 25%) and Behaviour & Mood
     * (Firmicutes 25%). The range/threshold-based insights (Metabolic / Verrucomicrobia,
     * Gut Wall / Blautia, Gas / Escherichia-Shigella, and the Firmicutes 25.99
     * resilience edge) define explicit boundaries and never use the tolerance.
     */
    public const TARGET_TOLERANCE = 0.25; // CONFIRMED targets are ranges; exact width may be refined

    /** Tone semantics for Stage-3 colours. Mirrors ReportContent::TONE_* so the two
     *  layers speak one vocabulary. 'good' = healthy/green, 'warn' = amber,
     *  'bad' = red. The per-band tones below are provisional colour HINTS pending
     *  the client's final palette/direction confirmation. */
    public const TONE_GOOD = 'good';

    public const TONE_WARN = 'warn';

    public const TONE_BAD = 'bad';

    /**
     * THE SIX INSIGHTS. Keyed by the report score_* column each one computes.
     *
     * Per insight:
     *   title            — display title (as rendered by ReportContent::healthInsights()).
     *   driver           — the bacteria whose % drives it; a key returned by
     *                      ReportContent::insightTaxonPercentages() (a canonical
     *                      phylum name or a genus display name).
     *   target           — the on-target reference value (used by the 'target'
     *                      predicate and as reference for the threshold insights).
     *   favourable_label — the band label that is the HEALTHY/green one for Stage-3
     *                      colouring. Usually 'Target'; Gas flips it to 'Low'.
     *   shared_note      — extra fixed explanatory copy shown on this insight (used
     *                      to explain why Firmicutes drives two insights).
     *   bands            — ORDERED list; the FIRST band whose 'when' predicate
     *                      matches the value wins. Predicate forms:
     *                        ['gt' => x] | ['gte' => x] | ['lt' => x] | ['lte' => x]
     *                        ['range' => [a, b]]  (a <= value < b)
     *                        'target'             (|value - target| <= TARGET_TOLERANCE)
     *                        'else'               (catch-all)
     *                      Each band carries: label, tone, comment (the client's
     *                      EXACT words), optional level (Gut Wall 3/2/1), and
     *                      needs_client_comment when the client hasn't supplied
     *                      distinct wording for it yet.
     */
    public const HEALTH_INSIGHT_RULES = [

        // 1. SKIN & ALLERGY RISK — driver Bacteroidetes %. CONFIRMED by client:
        //    High >30, Target 25 (as a range), Low <20, and the Medium either-side
        //    band uses the optimal/on-target wording (client's confirmed choice).
        'score_skin_allergy' => [
            'title' => 'Skin & Allergy Risk',
            // Client's scientific wording. Editable in Settings → Report Text →
            // Health Insight Descriptions; this stays the default/fallback.
            'desc' => 'This score evaluates microbiome characteristics associated with immune regulation, production of beneficial microbial metabolites and maintenance of the intestinal barrier, all of which influence systemic inflammation and allergic susceptibility. The assessment identifies microbial patterns that may increase or reduce the risk of microbiome-associated skin and immune dysfunction.',
            'driver' => 'Bacteroidetes',
            'target' => 25.0, // CONFIRMED
            'favourable_label' => 'Target',
            'shared_note' => null,
            'bands' => [
                ['when' => ['gt' => 30], 'label' => 'High', 'tone' => self::TONE_BAD, // CONFIRMED >30
                    'comment' => 'Higher levels of Bacteroidetes have been associated with an increased risk of skin sensitivity and allergic conditions, suggesting the immune system may be more reactive.'],
                ['when' => 'target', 'label' => 'Target', 'tone' => self::TONE_GOOD, // CONFIRMED target 25 (as a range via TARGET_TOLERANCE)
                    'comment' => 'Bacteroidetes are within the optimal range, suggesting a balanced microbial community that supports normal immune function.'],
                ['when' => ['lt' => 20], 'label' => 'Low', 'tone' => self::TONE_WARN, // CONFIRMED <20
                    'comment' => 'Low levels of Bacteroidetes may indicate a reduced capacity to produce beneficial metabolites that help regulate the immune system, although this is generally considered a lower risk for skin allergies than elevated levels. Improving overall microbiome balance and diversity may help restore this group naturally.'],
                // Medium = either side of target (20–25 and 25–30). CONFIRMED: the
                // client is happy for medium to carry the optimal/on-target wording
                // (positive, reassuring). Colour stays AMBER (the in-between band) —
                // only the 25% target band is green.
                ['when' => 'else', 'label' => 'Medium', 'tone' => self::TONE_WARN, // CONFIRMED optimal-style wording, amber
                    'comment' => 'Bacteroidetes are within the optimal range, suggesting a balanced microbial community that supports normal immune function.'],
            ],
        ],

        // 2. BEHAVIOUR & MOOD BALANCE — driver Firmicutes %. High >25, Target 25 (as
        //    a range via TARGET_TOLERANCE), Low <25; no medium band.
        'score_behaviour_mood' => [
            'title' => 'Behaviour & Mood Balance',
            // Client's scientific wording (Firmicutes / gut-brain axis).
            'desc' => 'Evaluates the abundance of Firmicutes, a major bacterial phylum that includes many beneficial species involved in the gut-brain axis. These bacteria support the production of short-chain fatty acids and stimulate pathways involved in serotonin synthesis, helping to regulate mood, behaviour and stress resilience through microbiome-brain communication.',
            'driver' => 'Firmicutes',
            'target' => 25.0, // per original spec
            'favourable_label' => 'Target',
            'shared_note' => null,
            'bands' => [
                // Target checked first so the ± tolerance window wins over the knife-
                // edge; High/Low then cover everything outside the window.
                ['when' => 'target', 'label' => 'Target', 'tone' => self::TONE_GOOD, // CONFIRMED target 25 (as a range via TARGET_TOLERANCE)
                    'comment' => 'Firmicutes are within the target range, suggesting a balanced microbiome that supports healthy gut-brain communication and normal behaviour and mood.'],
                ['when' => ['gt' => 25], 'label' => 'High', 'tone' => self::TONE_BAD,
                    'comment' => 'Firmicutes are above the target range. Elevated levels have been associated with alterations in the gut-brain axis and may indicate a microbiome that is less balanced in supporting normal behaviour and emotional wellbeing.'],
                ['when' => ['lt' => 25], 'label' => 'Low', 'tone' => self::TONE_WARN,
                    'comment' => 'Firmicutes are below the target range. Reduced levels may indicate a lower abundance of beneficial bacteria involved in producing compounds that support gut health, behaviour and emotional balance.'],
            ],
        ],

        // 3. METABOLIC HEALTH — driver Verrucomicrobia %. CONFIRMED 3-band RANGE:
        //    Low <1 / Healthy Optimal 1.0–4.0 (inclusive) / High >4. No point target
        //    and NO tolerance here — the healthy band is an explicit range, so this
        //    insight is banded purely by the numeric edges. Absent (0%) → Low. The
        //    favourable/green band is the 1–4% Healthy Optimal range; both ends are
        //    concern. Comments are the client's exact (deliberately short) wording.
        'score_gut_barrier' => [
            // "Gut Barrier" deliberately removed: that describes the Blautia-driven
            // insight (Gut Wall Integrity), so having it here — on the
            // Verrucomicrobia insight — was confusing. Client's wording. The field
            // KEY (score_gut_barrier) is unchanged; this is display only.
            'title' => 'Metabolic Health',
            // The ONLY insight the client did not supply new scientific wording for,
            // so this keeps the ORIGINAL Stage-3 copy as the placeholder (never blank).
            // Surfaced as the odd-one-out in the Settings helper text so she can update
            // it herself when she has the wording.
            'desc' => 'Reflects the functional capacity of the gut barrier and efficiency of nutrient metabolism.',
            'driver' => 'Verrucomicrobia',
            // No 'target' point — the healthy band is a range; kept null so the
            // tolerance mechanism is never engaged for this insight.
            'target' => null,
            'favourable_label' => 'Healthy Optimal',
            'shared_note' => null,
            'bands' => [
                // <1% OR absent → Low. A missing/absent genus is stored as 0 in
                // Stage 1, so 0 < 1 lands here naturally. Absent→Low is CONFIRMED.
                ['when' => ['lt' => 1], 'label' => 'Low', 'tone' => self::TONE_WARN, // CONFIRMED (incl. absent = 0)
                    'comment' => 'Reduced metabolic support'],
                ['when' => ['gt' => 4], 'label' => 'High', 'tone' => self::TONE_BAD, // >4.0
                    'comment' => 'Metabolic stress/adaptation – investigate in context'],
                // Everything left is 1.0–4.0 inclusive → Healthy Optimal (green).
                ['when' => 'else', 'label' => 'Healthy Optimal', 'tone' => self::TONE_GOOD,
                    'comment' => 'Healthy metabolic function'],
            ],
        ],

        // 4. GUT WALL INTEGRITY — driver Blautia %. CONFIRMED three discrete score
        //    bands: <2% Leaky Gut (score 1) / 2% to <3% Disrupted (score 2) / ≥3%
        //    Optimal Health (score 3, incl. 4%, 5%+). Edge behaviour is CONFIRMED.
        'score_gut_wall' => [
            'title' => 'Gut Wall Integrity',
            // Client's scientific wording. NB: the client calls this her "Gut Barrier"
            // comment and it is about BLAUTIA — so it belongs to THIS field
            // (score_gut_wall, driver Blautia), NOT to score_gut_barrier (which is
            // Metabolic Health / Verrucomicrobia). Mapped by driver, not field name.
            'desc' => 'Evaluates the abundance of Blautia, a beneficial bacterial group associated with maintaining intestinal barrier integrity and supporting anti-inflammatory activity within the gut. Reduced levels may be associated with impaired gut barrier function, increased intestinal permeability and greater exposure of the immune system to secondary metabolites and toxins.',
            'driver' => 'Blautia',
            'target' => 3.0,
            'favourable_label' => 'Optimal Health',
            'shared_note' => null,
            'bands' => [
                // ≥3% (incl. 4%, 5%+) → Optimal Health (top band, score 3). CONFIRMED.
                ['when' => ['gte' => 3], 'label' => 'Optimal Health', 'level' => 3, 'tone' => self::TONE_GOOD,
                    'comment' => 'Blautia levels are within the target range, suggesting good gut wall integrity and a healthy intestinal barrier that supports nutrient absorption and immune function.'],
                ['when' => ['range' => [2, 3]], 'label' => 'Disrupted', 'level' => 2, 'tone' => self::TONE_WARN, // 2% to <3%
                    'comment' => 'Blautia levels are below the target range, suggesting mild disruption to the gut wall. This may reduce the gut\'s ability to maintain a strong barrier and can contribute to digestive sensitivity and inflammation.'],
                // <2% → Leaky Gut (score 1). CONFIRMED.
                ['when' => ['lt' => 2], 'label' => 'Leaky Gut', 'level' => 1, 'tone' => self::TONE_BAD,
                    'comment' => 'Blautia levels are significantly reduced, suggesting poor gut wall integrity and increased intestinal permeability ("leaky gut"). This may allow unwanted substances to cross the gut barrier, contributing to inflammation and immune dysfunction.'],
            ],
        ],

        // 5. GAS & DIGESTIVE COMFORT — driver Escherichia/Shigella %. CONFIRMED.
        //    NB: here LOW is FAVOURABLE (client: low shows green) — favourable_label
        //    is 'Low'. The band computation is the usual value→band; only the
        //    good/bad DIRECTION differs, captured for Stage-3 colours.
        'score_gas_digestive' => [
            'title' => 'Gas & Digestive Comfort',
            // Client's scientific wording (Escherichia/Shigella).
            'desc' => 'Evaluates the abundance of Escherichia/Shigella, bacterial groups that can increase during gut microbial imbalance and are associated with intestinal inflammation and digestive disturbance. Elevated levels may indicate reduced microbial stability and a greater likelihood of gastrointestinal discomfort, altered stool quality and impaired digestive health.',
            'driver' => 'Escherichia/Shigella',
            'target' => 0.5, // CONFIRMED
            'favourable_label' => 'Low', // low is GOOD here
            'shared_note' => null,
            'bands' => [
                ['when' => ['gt' => 0.5], 'label' => 'High', 'tone' => self::TONE_BAD, // CONFIRMED >0.5
                    'comment' => 'Escherichia/Shigella levels are above the target range, suggesting an increased likelihood of excess gas, digestive discomfort and intestinal irritation.'],
                ['when' => ['lt' => 0.5], 'label' => 'Low', 'tone' => self::TONE_GOOD, // CONFIRMED <0.5 — favourable
                    'comment' => 'Escherichia/Shigella levels are low, which is considered beneficial and consistent with a healthy, well-balanced microbiome that is less likely to contribute to digestive discomfort or excess gas.'],
                // Exactly 0.5 → on target. No tolerance window here (a ± window would
                // contradict the CONFIRMED "Low is anything <0.5" edge).
                ['when' => 'else', 'label' => 'Target', 'tone' => self::TONE_GOOD, // CONFIRMED target 0.5
                    'comment' => 'Escherichia/Shigella levels are within the target range, suggesting a balanced gut environment that supports normal digestion and digestive comfort.'],
            ],
        ],

        // 6. ENVIRONMENTAL STRESS RESILIENCE — driver Firmicutes %. Thresholds per
        //    spec (High >25.99, Target ~25, Low <25). The Target band is the explicit
        //    [25, 25.99] range (asymmetric), so no tolerance is used here. Carries the
        //    shared note explaining why Firmicutes drives two insights.
        'score_stress_resilience' => [
            'title' => 'Environmental Stress Resilience',
            // Client's scientific wording (Firmicutes / resilience).
            'desc' => 'Assesses Firmicutes, a dominant bacterial phylum associated with microbial resilience, metabolic flexibility and maintenance of a stable gut ecosystem. Adequate abundance supports resistance to environmental challenges, helping the microbiome recover from dietary changes, stress and other factors that may disrupt microbial balance.',
            'driver' => 'Firmicutes',
            'target' => 25.0,
            'favourable_label' => 'Target',
            'shared_note' => 'Firmicutes play multiple roles within the gut microbiome, so they are used to assess both gut-brain communication and the microbiome\'s resilience to environmental change.',
            'bands' => [
                ['when' => ['gt' => 25.99], 'label' => 'High', 'tone' => self::TONE_BAD, // per spec >25.99
                    'comment' => 'Firmicutes are above the target range, suggesting the microbiome is outside the optimal balance for environmental resilience.'],
                ['when' => ['lt' => 25], 'label' => 'Low', 'tone' => self::TONE_WARN, // per spec <25
                    'comment' => 'Firmicutes are below the target range, suggesting the microbiome may be less resilient to environmental stressors such as dietary changes, travel, illness or medication.'],
                // [25, 25.99] = Target (~25).
                ['when' => 'else', 'label' => 'Target', 'tone' => self::TONE_GOOD,
                    'comment' => 'Firmicutes are within the target range, suggesting a balanced microbiome that is well adapted to cope with normal environmental challenges.'],
            ],
        ],
    ];

    /** The report score_* columns these rules own (config keys, stable order). */
    public static function scoreFields(): array
    {
        return array_keys(self::HEALTH_INSIGHT_RULES);
    }

    /**
     * The admin-editable point-target tolerance (Settings value when a sane number,
     * else the TARGET_TOLERANCE constant). DISPLAY-ONLY: it widens/narrows the
     * on-target window for the two 'target' insights, changing only which BAND LABEL
     * they show — it does not feed classification, plan routing or the nutritionist
     * trigger. Blank/unset/out-of-range → the constant, so behaviour is IDENTICAL to
     * today until edited. The 0–2 guard mirrors the Settings validation so the read
     * path is defensive on its own.
     */
    public static function targetTolerance(): float
    {
        $raw = Setting::get(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE);

        if (is_numeric($raw)) {
            $value = (float) $raw;
            if ($value >= 0.0 && $value <= 2.0) {
                return $value;
            }
        }

        return self::TARGET_TOLERANCE;
    }

    /**
     * The Settings key holding the admin-editable description for one insight, e.g.
     * "health_insight_desc_score_gut_wall". Derived from the field so the six settings
     * and the six insights can never drift apart (add an insight → it gets a field).
     * The config's 'desc' remains the DEFAULT: an unset/blank setting falls back to it,
     * so a description can never render blank. Resolved by
     * ReportContent::insightDescription().
     */
    public static function descriptionSettingKey(string $field): string
    {
        return 'health_insight_desc_'.$field;
    }

    /** Every distinct band label a computed/overridden insight can carry (across
     *  all six) — the union used to keep the score-enum validator in step. */
    public static function allBandLabels(): array
    {
        $labels = [];
        foreach (self::HEALTH_INSIGHT_RULES as $cfg) {
            foreach ($cfg['bands'] as $band) {
                $labels[$band['label']] = true;
            }
        }

        return array_keys($labels);
    }

    /** Dropdown options (label => label) for one insight's admin-override select,
     *  in config order and de-duplicated. */
    public static function labelOptions(string $field): array
    {
        $options = [];
        foreach (self::HEALTH_INSIGHT_RULES[$field]['bands'] ?? [] as $band) {
            $options[$band['label']] = $band['label'];
        }

        return $options;
    }

    /**
     * Compute one insight from its driver percentage: the first band whose predicate
     * matches wins. Returns the full descriptor (label, comment, tone, direction,
     * level, shared_note, needs_client_comment). This is the DETERMINISTIC value
     * that seeds score_* at generation time.
     *
     * @return array{field:string,title:string,driver:string,value:float,label:string,comment:?string,tone:?string,favourable:bool,level:?int,shared_note:?string,needs_client_comment:bool}
     */
    public static function computeInsight(string $field, float $value): array
    {
        $cfg = self::HEALTH_INSIGHT_RULES[$field] ?? null;
        if ($cfg === null) {
            return self::descriptor($field, [], null, $value);
        }

        $target = (float) ($cfg['target'] ?? 0);
        foreach ($cfg['bands'] as $band) {
            if (self::predicateMatches($band['when'], $value, $target)) {
                return self::descriptor($field, $cfg, $band, $value);
            }
        }

        // No band matched (a config without an 'else'/covering band) — return a
        // safe empty descriptor rather than throwing.
        return self::descriptor($field, $cfg, null, $value);
    }

    /**
     * Describe an insight by an ALREADY-CHOSEN band label (e.g. the stored,
     * possibly admin-overridden score_* value) rather than by a raw percentage. So
     * the comment/tone always follow the label actually shown — an override changes
     * both. An unknown/empty label yields a descriptor with no comment/tone.
     */
    public static function describeByLabel(string $field, ?string $label): array
    {
        $cfg = self::HEALTH_INSIGHT_RULES[$field] ?? [];
        $match = null;
        foreach ($cfg['bands'] ?? [] as $band) {
            if ($band['label'] === $label) {
                $match = $band;
                break;
            }
        }

        return self::descriptor($field, $cfg, $match, null);
    }

    /**
     * Compute the six score_* labels from a percentages map (as produced by
     * ReportContent::insightTaxonPercentages* — driver display-name => percent).
     * A missing driver defaults to 0, which bands deterministically (e.g. absent
     * Verrucomicrobia → Low, absent Blautia → Leaky Gut).
     *
     * @param  array<string,float|int>  $percentages
     * @return array<string,string>  score_field => band label
     */
    public static function computeScores(array $percentages): array
    {
        $scores = [];
        foreach (self::HEALTH_INSIGHT_RULES as $field => $cfg) {
            $value = (float) ($percentages[$cfg['driver']] ?? 0);
            $scores[$field] = self::computeInsight($field, $value)['label'];
        }

        return $scores;
    }

    /** Evaluate one declarative band predicate against a value. */
    private static function predicateMatches(array|string $when, float $value, float $target): bool
    {
        if ($when === 'else') {
            return true;
        }
        if ($when === 'target') {
            return abs($value - $target) <= self::targetTolerance();
        }
        if (isset($when['gt'])) {
            return $value > $when['gt'];
        }
        if (isset($when['gte'])) {
            return $value >= $when['gte'];
        }
        if (isset($when['lt'])) {
            return $value < $when['lt'];
        }
        if (isset($when['lte'])) {
            return $value <= $when['lte'];
        }
        if (isset($when['range'])) {
            [$lo, $hi] = $when['range'];

            return $value >= $lo && $value < $hi;
        }

        return false;
    }

    /** Assemble the descriptor for a (field, config, matched-band, value) tuple. */
    private static function descriptor(string $field, array $cfg, ?array $band, ?float $value): array
    {
        return [
            'field' => $field,
            'title' => $cfg['title'] ?? '',
            'desc' => $cfg['desc'] ?? '',
            'driver' => $cfg['driver'] ?? '',
            'value' => $value,
            'label' => $band['label'] ?? null,
            'comment' => $band['comment'] ?? null,
            'tone' => $band['tone'] ?? null,
            // The healthy/green band for Stage-3 colouring (Gas flips it to Low).
            'favourable' => $band !== null && ($band['label'] ?? null) === ($cfg['favourable_label'] ?? null),
            'level' => $band['level'] ?? null,
            'shared_note' => $cfg['shared_note'] ?? null,
            'needs_client_comment' => (bool) ($band['needs_client_comment'] ?? false),
        ];
    }
}
