<?php

namespace App\Support;

use App\Models\Report;
use App\Models\Setting;

/**
 * SHARED report content/data for BOTH report templates:
 *   - Web view: resources/views/report/show.blade.php  (Tailwind + Chart.js)
 *   - PDF:      resources/views/report/pdf.blade.php    (DomPDF, server-side SVG)
 *
 * The two templates are intentionally SEPARATE because DomPDF cannot use the
 * web's Tailwind CSS (and cannot run the web's Chart.js). Styling therefore
 * differs per template and is NOT shared. The underlying DATA, however, must be
 * identical in both — so it lives here once. Change a microbe's copy, an insight
 * description, the healthy-dog baseline or a phylum colour in this file and BOTH
 * templates pick it up. See docs/report-pdf.md.
 */
class ReportContent
{
    /**
     * Canonical phylum -> brand colour map (Biome4Pets report palette, applied in
     * order). One fixed colour per phylum so the SAME phylum reads identically in
     * the web Chart.js pies/donut, the PDF's server-side SVG pies, and the legend.
     * Any phylum beyond these six (rare/uncategorised) falls back to a muted grey
     * so the six brand colours stay the visual anchors.
     */
    public const PHYLUM_COLORS = [
        'Bacteroidetes'   => '#6CE5E8',
        'Fusobacteria'    => '#4168D5',
        'Firmicutes'      => '#2D8BBA',
        'Proteobacteria'  => '#1B8165',
        'Verrucomicrobia' => '#2F5F98',
        'Other'           => '#31356E',
    ];

    /** Fallback colour for any phylum not in PHYLUM_COLORS (muted, non-palette). */
    public const PHYLUM_COLOR_FALLBACK = '#9ca3af';

    /** Reference "healthy dog" phylum distribution (the comparison baseline). */
    public const HEALTHY_DOG_PHYLA = [
        'Bacteroidetes'   => 33.7,
        'Fusobacteria'    => 22.1,
        'Firmicutes'      => 18.3,
        'Proteobacteria'  => 9.6,
        'Verrucomicrobia' => 1.9,
        'Other'           => 14.4,
    ];

    /** Colour for a phylum name, with the shared fallback. */
    public static function phylumColor(string $name): string
    {
        return self::PHYLUM_COLORS[$name] ?? self::PHYLUM_COLOR_FALLBACK;
    }

    /*
    |---------------------------------------------------------------------------
    | Static report text blocks (the "Help and Contacts" section)
    |---------------------------------------------------------------------------
    | Same on every report, admin-editable in Settings. Resolved HERE (one place)
    | so the web report and the PDF read identical copy and can never drift, and
    | a blank setting transparently falls back to the original hardcoded default.
    | Render escaped (these are admin-entered) — see the views' nl2br(e(...)) use.
    */

    /** A static report-text block, falling back to its default when blank. */
    public static function reportText(string $key, string $default): string
    {
        $value = trim((string) Setting::get($key));

        return $value !== '' ? $value : $default;
    }

    /** The "About This Report" block (method + disclaimer), default-backed. */
    public static function reportAboutText(): string
    {
        return self::reportText(Setting::REPORT_ABOUT_TEXT, Setting::REPORT_ABOUT_TEXT_DEFAULT);
    }

    /** The "Support & Next Steps" block, default-backed. */
    public static function reportSupportText(): string
    {
        return self::reportText(Setting::REPORT_SUPPORT_TEXT, Setting::REPORT_SUPPORT_TEXT_DEFAULT);
    }

    /** The "Our Approach" block as a list of non-blank bullet lines. */
    public static function reportApproachLines(): array
    {
        $text = self::reportText(Setting::REPORT_APPROACH_TEXT, Setting::REPORT_APPROACH_TEXT_DEFAULT);

        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $text)),
            fn (string $line): bool => $line !== '',
        ));
    }

    /*
    |---------------------------------------------------------------------------
    | Clinical interpretation bands (SINGLE SOURCE OF TRUTH)
    |---------------------------------------------------------------------------
    | The cutoffs + labels for the three headline microbiome metrics (Diversity,
    | Species Richness, Dysbiosis) used to live triplicated across the web report,
    | the PDF report AND the classification logic in CsvParserService — so editing
    | one silently disagreed with the others. They now live here once and are
    | consumed by all three, so the printed bands and the computed classification
    | badge can never drift apart.
    |
    | These are CLINICAL cutoffs, intentionally kept in CODE (not exposed as
    | client-editable settings): a non-expert changing them would mislabel real
    | samples. Centralised here only to remove the former duplication — the VALUES
    | are unchanged from the original three copies.
    |
    | Each band() method preserves the EXACT original comparison semantics
    | (lower bound exclusive `<`, middle-band upper bound inclusive `<=`) and
    | returns a label plus a semantic tone ('bad' | 'warn' | 'good'). Each view
    | maps the tone to its own palette (Tailwind classes for the web, hex for the
    | PDF) — styling stays per-template, only the numbers/labels/tones are shared.
    */

    /** Diversity: Low when score < this. */
    public const DIVERSITY_LOW_MAX = 1.9;

    /** Diversity: High when score > this (Medium between, inclusive of this). */
    public const DIVERSITY_HIGH_MIN = 2.5;

    /** Diversity at/above which a microbiome may be classified "Stable" (the
     *  classification-only upper threshold — distinct from the display bands,
     *  whose top band starts at DIVERSITY_HIGH_MIN). */
    public const DIVERSITY_STABLE_MIN = 3.0;

    /** The three microbiome classification verdicts (the only values classify()
     *  returns). Named so plan-routing + the quality grader share one definition
     *  of "unwell" instead of repeating the literal strings. */
    public const CLASSIFICATION_STABLE = 'Stable';

    public const CLASSIFICATION_IMBALANCED = 'Imbalanced';

    public const CLASSIFICATION_DEPLETED = 'Imbalanced & Depleted';

    /** Species richness: Low when count < this. */
    public const RICHNESS_LOW_MAX = 400;

    /** Species richness: Healthy when count > this (Moderate between, inclusive). */
    public const RICHNESS_HEALTHY_MIN = 650;

    /** Dysbiosis: Healthy band lower bound (below = Low). */
    public const DYSBIOSIS_HEALTHY_MIN = 0.2;

    /** Dysbiosis: Healthy band upper bound, inclusive (above = High). */
    public const DYSBIOSIS_HEALTHY_MAX = 0.5;

    /** Tone semantics, kept stable so views can map them to their own palettes. */
    public const TONE_BAD = 'bad';

    public const TONE_WARN = 'warn';

    public const TONE_GOOD = 'good';

    /** Diversity band (label + tone) for a score. */
    public static function diversityBand(float $score): array
    {
        if ($score < self::DIVERSITY_LOW_MAX) {
            return ['label' => 'Low', 'tone' => self::TONE_BAD];
        }
        if ($score <= self::DIVERSITY_HIGH_MIN) {
            return ['label' => 'Medium', 'tone' => self::TONE_WARN];
        }

        return ['label' => 'High', 'tone' => self::TONE_GOOD];
    }

    /** Species-richness band (label + tone) for a count. */
    public static function richnessBand(float $richness): array
    {
        if ($richness < self::RICHNESS_LOW_MAX) {
            return ['label' => 'Low', 'tone' => self::TONE_BAD];
        }
        if ($richness <= self::RICHNESS_HEALTHY_MIN) {
            return ['label' => 'Moderate', 'tone' => self::TONE_WARN];
        }

        return ['label' => 'Healthy', 'tone' => self::TONE_GOOD];
    }

    /** Dysbiosis band (label + tone) for a score. NB: Low is a WARNING (amber),
     *  not "good" — only the middle band is Healthy. */
    public static function dysbiosisBand(float $score): array
    {
        if ($score < self::DYSBIOSIS_HEALTHY_MIN) {
            return ['label' => 'Low', 'tone' => self::TONE_WARN];
        }
        if ($score <= self::DYSBIOSIS_HEALTHY_MAX) {
            return ['label' => 'Healthy', 'tone' => self::TONE_GOOD];
        }

        return ['label' => 'High', 'tone' => self::TONE_BAD];
    }

    /**
     * The deterministic microbiome classification — the SINGLE definition shared
     * by report generation (CsvParserService) and anywhere a badge is shown. Uses
     * the same cutoff constants as the display bands so the badge and the printed
     * bands can't disagree (e.g. "Imbalanced & Depleted" uses the same 1.9 / 400
     * Low cutoffs; "Stable" requires dysbiosis within the same 0.2–0.5 Healthy band).
     */
    public static function classify(float $diversity, float $richness, float $dysbiosis): string
    {
        if ($diversity >= self::DIVERSITY_STABLE_MIN
            && $dysbiosis >= self::DYSBIOSIS_HEALTHY_MIN
            && $dysbiosis <= self::DYSBIOSIS_HEALTHY_MAX) {
            return self::CLASSIFICATION_STABLE;
        }

        if ($diversity < self::DIVERSITY_LOW_MAX || $richness < self::RICHNESS_LOW_MAX) {
            return self::CLASSIFICATION_DEPLETED;
        }

        return self::CLASSIFICATION_IMBALANCED;
    }

    /**
     * Whether a classification verdict means the pet is unwell (Imbalanced or
     * Imbalanced & Depleted). "Stable", null and any unknown value are NOT unwell,
     * so callers degrade safely. Shared by the classification-gated plan router
     * (the maintenance plan is only valid for a non-unwell result) and the quality
     * grader's "unwell but no plan matched" safety flag.
     */
    public static function isUnwellClassification(?string $classification): bool
    {
        return in_array(
            $classification,
            [self::CLASSIFICATION_IMBALANCED, self::CLASSIFICATION_DEPLETED],
            true,
        );
    }

    /** Printed legend rows (label + tone + range string) for the diversity card. */
    public static function diversityLegend(): array
    {
        return [
            ['label' => 'Low', 'tone' => self::TONE_BAD, 'range' => '< '.self::num(self::DIVERSITY_LOW_MAX)],
            ['label' => 'Medium', 'tone' => self::TONE_WARN, 'range' => self::num(self::DIVERSITY_LOW_MAX).' - '.self::num(self::DIVERSITY_HIGH_MIN)],
            ['label' => 'High', 'tone' => self::TONE_GOOD, 'range' => '> '.self::num(self::DIVERSITY_HIGH_MIN)],
        ];
    }

    /** Printed legend rows for the species-richness card. */
    public static function richnessLegend(): array
    {
        return [
            ['label' => 'Low', 'tone' => self::TONE_BAD, 'range' => '< '.self::num(self::RICHNESS_LOW_MAX)],
            ['label' => 'Moderate', 'tone' => self::TONE_WARN, 'range' => self::num(self::RICHNESS_LOW_MAX).' - '.self::num(self::RICHNESS_HEALTHY_MIN)],
            ['label' => 'Healthy', 'tone' => self::TONE_GOOD, 'range' => '> '.self::num(self::RICHNESS_HEALTHY_MIN)],
        ];
    }

    /** Printed legend rows for the dysbiosis card. */
    public static function dysbiosisLegend(): array
    {
        return [
            ['label' => 'Low', 'tone' => self::TONE_WARN, 'range' => '< '.self::num(self::DYSBIOSIS_HEALTHY_MIN)],
            ['label' => 'Healthy', 'tone' => self::TONE_GOOD, 'range' => self::num(self::DYSBIOSIS_HEALTHY_MIN).' - '.self::num(self::DYSBIOSIS_HEALTHY_MAX)],
            ['label' => 'High', 'tone' => self::TONE_BAD, 'range' => '> '.self::num(self::DYSBIOSIS_HEALTHY_MAX)],
        ];
    }

    /** Format a cutoff for display, trimming trailing zeros (1.9 → "1.9", 400 → "400"). */
    public static function num(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /*
    |---------------------------------------------------------------------------
    | Per-phylum reference bands (SINGLE SOURCE) + deterministic band verdict
    |---------------------------------------------------------------------------
    | The low/target/high reference numbers for the Key Microbes — the SAME
    | numbers the bar chart plots and the AI prose now explains. They live here
    | once so the prompt, the validator and the chart can't disagree. Where a
    | pet's value sits relative to its band is decided HERE in arithmetic, never
    | by the AI: value < low → low; value > high → high; otherwise within range.
    | (These are DISPLAY interpretation bands — NOT the plan-routing/AMR clinical
    | thresholds, which the client owns separately.)
    */
    public const PHYLUM_BANDS = [
        'Fusobacteria'   => ['low' => 10, 'target' => 18,  'high' => 25],
        'Bacteroidetes'  => ['low' => 10, 'target' => 20,  'high' => 40],
        'Firmicutes'     => ['low' => 13, 'target' => 26,  'high' => 45],
        'Proteobacteria' => ['low' => 5,  'target' => 9,   'high' => 18],
        'Prevotella'     => ['low' => 1,  'target' => 2.5, 'high' => 5],
    ];

    /**
     * Deterministic band verdict for a phylum value vs its reference band, or null
     * when the phylum has no defined band. band: 'low' (< low) | 'high' (> high) |
     * 'within' (between, inclusive). Arithmetic only — the AI never decides this.
     *
     * @return array{band:string, low:float, high:float, target:float, value:float}|null
     */
    public static function phylumBandVerdict(string $name, float $value): ?array
    {
        $bands = self::PHYLUM_BANDS[$name] ?? null;
        if ($bands === null) {
            return null;
        }

        $low = (float) $bands['low'];
        $high = (float) $bands['high'];
        $band = $value < $low ? 'low' : ($value > $high ? 'high' : 'within');

        return ['band' => $band, 'low' => $low, 'high' => $high, 'target' => (float) $bands['target'], 'value' => $value];
    }

    /**
     * The fixed-fact sentence handed to the AI for a phylum: states the value, the
     * DETERMINED band, and the typical range — so the model explains it, never
     * re-judges it. Null when the phylum has no band.
     */
    public static function phylumBandSentence(string $name, float $value): ?string
    {
        $v = self::phylumBandVerdict($name, $value);
        if ($v === null) {
            return null;
        }

        $range = self::num($v['low']).'% to '.self::num($v['high']).'%';
        $word = match ($v['band']) {
            'low' => 'LOW (below the typical range of '.$range.')',
            'high' => 'HIGH (above the typical range of '.$range.')',
            default => 'WITHIN the typical range of '.$range,
        };

        return $name.' is '.self::num($value).'%, which is '.$word.'.';
    }

    /**
     * The pet's phyla reduced to the top 6 by value, with everything else
     * grouped into a single "Other" slice. Mirrors the web JS that feeds the
     * "Your Dog" pie and the phylum donut.
     */
    public static function topPhyla(array $phylumData): array
    {
        arsort($phylumData);
        $top6 = array_slice($phylumData, 0, 6, true);
        $restSum = array_sum(array_slice($phylumData, 6, null, true));
        if ($restSum > 0) {
            $top6['Other'] = round($restSum, 2);
        }

        return $top6;
    }

    /**
     * The key microbes: static educational copy (functions, considerations,
     * healthy ranges) plus this report's per-phylum AI interpretation and value.
     */
    public static function microbes(Report $report): array
    {
        $phylumData = $report->phylum_data ?? [];

        return [
            [
                'name' => 'Fusobacteria',
                'interpretation' => $report->ai_fusobacteria_interpretation,
                ...self::PHYLUM_BANDS['Fusobacteria'],
                'value' => $phylumData['Fusobacteria'] ?? 0,
                'functions' => [
                    'Protein breakdown – supports digestion of dietary protein and amino acid utilisation',
                    'Energy production – contributes to metabolite production within the gut',
                    'Microbiome balance marker – reflects dietary protein intake and feeding patterns',
                ],
                'considerations' => [
                    'High levels may indicate a proteolytic fermentation pattern and increased inflammatory metabolites',
                    'Certain species are associated with gastrointestinal irritation and imbalance',
                    'Levels are influenced by diet, particularly protein content',
                    'Balance with fibre-fermenting bacteria is important for optimal gut function',
                ],
            ],
            [
                'name' => 'Bacteroidetes',
                'interpretation' => $report->ai_bacteroidetes_interpretation,
                ...self::PHYLUM_BANDS['Bacteroidetes'],
                'value' => $phylumData['Bacteroidetes'] ?? 0,
                'functions' => [
                    'Carbohydrate digestion – supports breakdown of plant-based fibres',
                    'Protein and mucin metabolism – linked to meat and endogenous gut substrates',
                    'Immune interaction – can influence gut inflammation and barrier stability',
                ],
                'considerations' => [
                    'Elevated levels may be associated with gut inflammation or microbial imbalance',
                    'Some species are opportunistic and linked to infection if they cross the gut barrier',
                    'Increasing antibiotic resistance has been observed across multiple species',
                ],
            ],
            [
                'name' => 'Firmicutes',
                'interpretation' => $report->ai_firmicutes_interpretation,
                ...self::PHYLUM_BANDS['Firmicutes'],
                'value' => $phylumData['Firmicutes'] ?? 0,
                'functions' => [
                    'Immune regulation – supports gut barrier integrity and defence against harmful bacteria',
                    'Microbiome balance – helps maintain stability and coordination within the gut ecosystem',
                    'Short-chain fatty acid production – contributes to compounds that nourish the gut lining',
                    'Metabolic support – involved in energy production and overall gut environment health',
                ],
                'considerations' => [
                    'Some species are associated with toxin production and disease',
                    'Overgrowth may be linked to inflammation, diarrhoea, and gastrointestinal disturbance',
                    'Low levels may reduce gut resilience and protective immune function',
                    'Balance is critical as the majority of Firmicutes species are beneficial',
                ],
            ],
            [
                'name' => 'Proteobacteria',
                'interpretation' => $report->ai_proteobacteria_interpretation,
                ...self::PHYLUM_BANDS['Proteobacteria'],
                'value' => $phylumData['Proteobacteria'] ?? 0,
                'functions' => [
                    'Protein metabolism – associated with digestion of protein-rich diets',
                    'Core microbiome component – present in healthy dogs at controlled levels',
                    'Environmental responsiveness – levels can shift rapidly with diet and gut conditions',
                ],
                'considerations' => [
                    'Includes several pathogenic species (e.g. E. coli, Salmonella, Campylobacter, Klebsiella)',
                    'Elevated levels are commonly associated with gut dysbiosis and inflammation',
                    'May increase in dogs fed high-protein or highly processed diets',
                    'Overgrowth can indicate microbial imbalance or underlying disease',
                ],
            ],
            [
                'name' => 'Prevotella',
                'interpretation' => null,
                ...self::PHYLUM_BANDS['Prevotella'],
                'value' => $phylumData['Prevotella'] ?? 0,
                'functions' => [
                    'Fibre and carbohydrate breakdown – supports energy production from plant-based substrates',
                    'Protein metabolism – contributes to amino acid utilisation',
                    'Microbiome balance marker – reflects dietary fibre and carbohydrate intake',
                ],
                'considerations' => [
                    'High levels may be associated with inflammation and gastrointestinal disturbance',
                    'Dominant species (P. copri) linked to inflammatory conditions such as arthritis',
                    'Levels increase with high carbohydrate, low protein diets',
                    'Balance with Bacteroides is important for optimal gut function',
                ],
            ],
        ];
    }

    /**
     * The key-microbe cards that actually have data to show. We don't yet retain
     * genus-level data, so the Prevotella card is always empty (value 0, no
     * interpretation) and renders as a broken placeholder — drop any such card
     * rather than show it. Data-driven, not name-specific: a card is kept when it
     * has a non-zero value OR an AI interpretation, so the four phyla always show,
     * and Prevotella would reappear automatically if genus data is added later.
     */
    public static function keyMicrobes(Report $report): array
    {
        return array_values(array_filter(
            self::microbes($report),
            fn (array $m): bool => ((float) ($m['value'] ?? 0)) > 0 || filled($m['interpretation'] ?? null),
        ));
    }

    /** Microbiome-driven health insight scores (title, the report's score, copy). */
    public static function insights(Report $report): array
    {
        return [
            [
                'title' => 'Gut Wall Integrity',
                'score' => $report->score_gut_wall,
                'desc' => 'Measures the strength and resilience of the intestinal lining based on key bacterial markers.',
            ],
            [
                'title' => 'Skin & Allergy Risk',
                'score' => $report->score_skin_allergy,
                'desc' => 'Assesses the likelihood of skin sensitivities and allergic responses linked to gut microbiome imbalances.',
            ],
            [
                'title' => 'Behaviour & Mood Balance',
                'score' => $report->score_behaviour_mood,
                'desc' => 'Evaluates the gut-brain axis indicators that influence mood, anxiety, and behavioural patterns.',
            ],
            [
                'title' => 'Gut Barrier & Metabolic Health',
                'score' => $report->score_gut_barrier,
                'desc' => 'Reflects the functional capacity of the gut barrier and efficiency of nutrient metabolism.',
            ],
            [
                'title' => 'Gas & Digestive Comfort',
                'score' => $report->score_gas_digestive,
                'desc' => 'Indicates the level of gas-producing bacteria and overall digestive comfort.',
            ],
            [
                'title' => 'Environmental Stress Resilience',
                'score' => $report->score_stress_resilience,
                'desc' => "Measures the microbiome's ability to withstand environmental stressors and maintain stability.",
            ],
        ];
    }
}
