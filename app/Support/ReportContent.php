<?php

namespace App\Support;

use App\Models\Report;

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
     * Canonical phylum -> brand colour map. Used by the web Chart.js pies/donut
     * and by the PDF's server-side SVG pies, so the two documents always agree.
     */
    public const PHYLUM_COLORS = [
        'Bacteroidetes'   => '#4E7BA4',
        'Fusobacteria'    => '#3b82f6',
        'Firmicutes'      => '#f97316',
        'Proteobacteria'  => '#ef4444',
        'Verrucomicrobia' => '#9ca3af',
        'Other'           => '#d1d5db',
    ];

    /** Fallback colour for any phylum not in PHYLUM_COLORS. */
    public const PHYLUM_COLOR_FALLBACK = '#6b7280';

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
     * The 5 key microbes: static educational copy (functions, considerations,
     * healthy ranges) plus this report's per-phylum AI interpretation and value.
     */
    public static function microbes(Report $report): array
    {
        $phylumData = $report->phylum_data ?? [];

        return [
            [
                'name' => 'Fusobacteria',
                'interpretation' => $report->ai_fusobacteria_interpretation,
                'target' => 18, 'high' => 25, 'low' => 10,
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
                'target' => 20, 'high' => 40, 'low' => 10,
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
                'target' => 26, 'high' => 45, 'low' => 13,
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
                'target' => 9, 'high' => 18, 'low' => 5,
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
                'target' => 2.5, 'high' => 5, 'low' => 1,
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
