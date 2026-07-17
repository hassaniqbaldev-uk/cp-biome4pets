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

    /**
     * Canonical phylum name => the naming variants that mean the SAME phylum. Labs
     * following the newer ICNP/GTDB taxonomy print the "-ota" forms (Bacteroidota,
     * Bacillota, Verrucomicrobiota, Pseudomonadota, …) where our data/baselines use
     * the older names. Without this a present phylum under a newer name would read
     * as 0. Match is case-insensitive; the canonical name is always its own alias.
     *
     * @var array<string,array<int,string>>
     */
    public const PHYLUM_ALIASES = [
        'Bacteroidetes'   => ['Bacteroidetes', 'Bacteroidota'],
        'Firmicutes'      => ['Firmicutes', 'Bacillota'],
        'Verrucomicrobia' => ['Verrucomicrobia', 'Verrucomicrobiota'],
        'Proteobacteria'  => ['Proteobacteria', 'Pseudomonadota'],
        'Fusobacteria'    => ['Fusobacteria', 'Fusobacteriota'],
        'Actinobacteria'  => ['Actinobacteria', 'Actinomycetota'],
    ];

    /**
     * A phylum's percentage from a phylum_data map, resilient to old-vs-new
     * taxonomy naming: sums every key that is an alias of the canonical name
     * (case-insensitive, whitespace-trimmed). A genuinely absent phylum returns 0.0
     * — never an error — so callers can treat absent as 0. Additive-safe: for the
     * old canonical names already in our data this returns exactly the stored value.
     */
    public static function phylumPercent(array $phylumData, string $canonical): float
    {
        $wanted = array_map('strtolower', self::PHYLUM_ALIASES[$canonical] ?? [$canonical]);

        $sum = 0.0;
        foreach ($phylumData as $name => $value) {
            if (in_array(strtolower(trim((string) $name)), $wanted, true)) {
                $sum += (float) $value;
            }
        }

        return round($sum, 2);
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

    /**
     * "Understanding Your Dog's Results": what each of the three Microbiome Overview
     * scores means. The client's EXACT wording — do not paraphrase. Lives here once
     * so the web report and the PDF render identical copy and can't drift (the
     * templates share data, not styling — see the class docblock).
     *
     * @return array<int,array{title:string,text:string}>
     */
    public static function resultsExplanations(): array
    {
        return [
            [
                'title' => 'Diversity',
                'text' => 'Diversity is measured using the Shannon Index, a standard method used to assess how varied and balanced the microbiome is. Higher scores indicate greater diversity and a more resilient microbiome.',
            ],
            [
                'title' => 'Species Richness',
                'text' => 'Species richness reflects the number of different bacterial species present. Higher numbers are typically associated with a more diverse and resilient microbiome.',
            ],
            [
                'title' => 'Dysbiosis Pattern Score',
                'text' => 'The Dysbiosis Pattern Score reflects the balance between Firmicutes and Bacteroidetes.',
            ],
        ];
    }

    /*
    |---------------------------------------------------------------------------
    | Kibble + imbalanced: the nutritionist diet-review recommendation
    |---------------------------------------------------------------------------
    | Shown in place of the generic nutritionist nudge when the pet is kibble-fed
    | AND its classification is Imbalanced / Imbalanced & Depleted — the client's
    | rule (see Report::recommendsDietReview()). Copy lives here once so the web
    | report and the PDF render identical wording and can't drift.
    */

    /** The nutritionist diet-review product the recommendation links to. */
    public const DIET_REVIEW_URL = 'https://biome4pets.com/products/microbiome-diet-review-optimisation-60-minutes';

    /** The client's EXACT wording for the recommendation — do not paraphrase. */
    public static function dietReviewText(): string
    {
        return "We recommend speaking with one of our nutritionists, as your dog's diet may be contributing to their microbiome imbalance. Gut health and nutrition go hand in hand, and by reviewing your dog's microbiome results alongside their current diet, our nutritionists can identify foods and feeding strategies that better support a healthy, balanced microbiome and help optimise long-term gut health.";
    }

    /** The loyalty/subscription discount note shown beside the link. */
    public static function dietReviewLoyaltyNote(): string
    {
        return 'If you are on a subscription or part of the loyalty programme, you get 10% off.';
    }

    /** Call-to-action label for the diet-review link. */
    public static function dietReviewLinkLabel(): string
    {
        return 'Book a microbiome diet review';
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

    /*
    |---------------------------------------------------------------------------
    | Stage 1: deterministic health-insights source data (READ HELPER ONLY)
    |---------------------------------------------------------------------------
    | The forthcoming six insights are driven by a fixed set of bacteria
    | percentages. This exposes each one per report as a single clean read — the
    | rule layer (next stage, once the client confirms thresholds) consumes THIS
    | and never re-derives from raw data. NO rule/band/colour/display logic here.
    |
    | Phyla come from phylum_data via the naming-robust phylumPercent(); genera
    | come from csv_data['insight_taxa'] (persisted by CsvParserService regardless
    | of top-20 ranking). Everything defaults to 0 when absent — including reports
    | generated before this stage, until they are backfilled — so a consumer never
    | hits a missing key.
    */

    /** Phyla the insights need, by canonical name (resolved naming-robustly). */
    public const INSIGHT_PHYLA = ['Bacteroidetes', 'Firmicutes', 'Verrucomicrobia'];

    /**
     * Genera the insights need: display name => the csv_data['insight_taxa'] storage
     * key written by CsvParserService::INSIGHT_GENERA. Keep the two in step.
     *
     * @var array<string,string>
     */
    public const INSIGHT_GENERA = [
        'Blautia' => 'blautia',
        'Escherichia/Shigella' => 'escherichia_shigella',
    ];

    /**
     * Every bacteria percentage the Stage-1 insights need, for ONE report, as a
     * display-name => percent map. Phyla are read naming-robustly from phylum_data;
     * genera from csv_data['insight_taxa']. Absent (incl. not-yet-backfilled
     * reports) reads 0.0, never a missing key or an error.
     *
     * @return array<string,float>
     */
    public static function insightTaxonPercentages(Report $report): array
    {
        return self::insightTaxonPercentagesFrom(
            $report->phylum_data ?? [],
            ($report->csv_data ?? [])['insight_taxa'] ?? [],
        );
    }

    /**
     * The same map, built from RAW arrays instead of a Report — used at generation
     * time (before a Report row exists) so the deterministic scores can be computed
     * from a Test's phylum_data + csv_data['insight_taxa']. The Report accessor
     * above delegates here so there is one definition.
     *
     * @param  array<string,float|int>  $phylumData  phylum name => percent
     * @param  array<string,float|int>  $insightTaxa  genus storage key => percent
     * @return array<string,float>
     */
    public static function insightTaxonPercentagesFrom(array $phylumData, array $insightTaxa): array
    {
        $out = [];
        foreach (self::INSIGHT_PHYLA as $name) {
            $out[$name] = self::phylumPercent($phylumData, $name);
        }
        foreach (self::INSIGHT_GENERA as $display => $storageKey) {
            $out[$display] = round((float) ($insightTaxa[$storageKey] ?? 0), 2);
        }

        return $out;
    }

    /**
     * A single Stage-1 insight bacteria's percentage for a report, by the same
     * name used as a key in insightTaxonPercentages (a canonical phylum name or a
     * genus display name). Unknown/absent → 0.0.
     */
    public static function insightTaxonPercent(Report $report, string $name): float
    {
        return self::insightTaxonPercentages($report)[$name] ?? 0.0;
    }

    /**
     * Stage 2: the six health insights fully described for THIS report — band label,
     * the client's fixed comment for that band, the good/bad direction (for Stage-3
     * colours), the driver percentage, and any shared note. Keyed by score field.
     *
     * The band is taken from the STORED score_* value (so an admin override changes
     * the label AND its comment together), and the descriptor is looked up from the
     * one config in HealthInsightRules. The driver percentage comes from the Stage-1
     * helper. Display/gauge overhaul is Stage 3 — this only exposes the values.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function healthInsights(Report $report): array
    {
        $percentages = self::insightTaxonPercentages($report);

        $out = [];
        foreach (HealthInsightRules::scoreFields() as $field) {
            $label = trim((string) ($report->{$field} ?? ''));
            $descriptor = HealthInsightRules::describeByLabel($field, $label !== '' ? $label : null);
            $descriptor['value'] = $percentages[$descriptor['driver']] ?? 0.0;
            // Admin-editable copy wins over the config default. Both the web card and
            // the PDF card read this method, so editing it in Settings updates both.
            $descriptor['desc'] = self::insightDescription($field);
            $out[$field] = $descriptor;
        }

        return $out;
    }

    /**
     * One insight's description: the admin-editable Settings value when set, otherwise
     * the config default (HealthInsightRules' 'desc'). Same blank-falls-back-to-default
     * contract as the other report-text blocks (see reportText()), so clearing the
     * field in Settings restores the original wording rather than rendering blank.
     */
    public static function insightDescription(string $field): string
    {
        $value = trim((string) Setting::get(HealthInsightRules::descriptionSettingKey($field)));

        return $value !== ''
            ? $value
            : (string) (HealthInsightRules::HEALTH_INSIGHT_RULES[$field]['desc'] ?? '');
    }
}
