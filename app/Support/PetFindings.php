<?php

namespace App\Support;

use App\Models\ProductRule;
use App\Models\Report;
use Illuminate\Support\Carbon;

/**
 * Builds the PET FINDINGS payload (plan-generation-prompt.md §2 shape) for the
 * plan copy generator. Works from either a saved Report or raw form-state
 * values (the report builder applies a plan before the report is persisted).
 *
 * Best-effort and honest: only fields that are actually present are emitted —
 * it never invents taxa, values, or scores.
 */
class PetFindings
{
    public static function fromReport(Report $report): array
    {
        return self::build([
            'pet_name' => $report->pet?->name,
            'sex' => $report->pet?->sex,
            'owner_name' => $report->petClient?->name,
            // Part 2: the notes history AS OF this report's date (report_date is
            // proxied from the linked test; collected_at backs it up).
            'health_notes' => $report->pet?->healthNotesForContext(
                $report->report_date ?? $report->test?->collected_at
            ),
            'report_date' => $report->report_date,
            'diversity_score' => $report->diversity_score,
            'species_richness' => $report->species_richness,
            'dysbiosis_score' => $report->dysbiosis_score,
            'microbiome_classification' => $report->microbiome_classification,
            'phylum_data' => $report->phylum_data ?? [],
        ]);
    }

    public static function build(array $data): array
    {
        $findings = [];

        if (filled($data['pet_name'] ?? null)) {
            $findings['pet_name'] = $data['pet_name'];
        }

        // The app is dog-only today.
        $findings['species'] = 'dog';

        // Pet sex + an explicit pronoun instruction. The plan-copy generator (the
        // recommendation/tips text) runs as a SEPARATE OpenAI call from the report
        // interpretations, so it must carry its own pronoun guidance or the tips
        // drift to the wrong gender. Emitting pronoun_guidance INTO the findings
        // means it reaches the model even if the system prompt is admin-overridden.
        // Unknown/blank sex ⇒ guidance says use the name or they/their, never guess.
        $findings['sex'] = PetPronouns::normalise($data['sex'] ?? null) ?? 'unknown';
        $findings['pronoun_guidance'] = PetPronouns::instruction($data['sex'] ?? null);

        if (filled($data['owner_name'] ?? null)) {
            $findings['owner_name'] = $data['owner_name'];
        }

        // Phase 2: owner-reported health notes as grounding context for the copy.
        // Omitted entirely when blank. The plan system prompt instructs the model
        // to treat these as owner-reported context, not a diagnosis.
        if (filled($data['health_notes'] ?? null)) {
            $findings['owner_reported_health_notes'] = trim((string) $data['health_notes']);
        }

        if (filled($data['report_date'] ?? null)) {
            $findings['report_date'] = self::formatDate($data['report_date']);
        }

        $scores = [];
        if (self::present($data['diversity_score'] ?? null)) {
            $scores['diversity_shannon'] = (float) $data['diversity_score'];
        }
        if (self::present($data['species_richness'] ?? null)) {
            $scores['species_richness'] = (int) $data['species_richness'];
        }
        if (self::present($data['dysbiosis_score'] ?? null)) {
            $scores['dysbiosis_pattern'] = (float) $data['dysbiosis_score'];
        }
        if (filled($data['microbiome_classification'] ?? null)) {
            $scores['classification'] = $data['microbiome_classification'];
        }
        if (! empty($scores)) {
            $findings['scores'] = $scores;
        }

        [$elevated, $low] = self::deriveTaxa($data['phylum_data'] ?? []);
        $findings['elevated'] = $elevated;
        $findings['low'] = $low;

        return $findings;
    }

    protected static function present($value): bool
    {
        return $value !== null && $value !== '';
    }

    protected static function formatDate($value): string
    {
        try {
            return Carbon::parse($value)->format('j F Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * Classify the phyla present in the report as elevated/low using the active
     * ProductRule thresholds. Only reports phyla that a rule actually flags — it
     * never fabricates a finding. Returns [elevated[], low[]].
     */
    protected static function deriveTaxa(array $phylumData): array
    {
        if (empty($phylumData)) {
            return [[], []];
        }

        try {
            $rules = ProductRule::query()->where('is_active', true)->get();
        } catch (\Throwable) {
            return [[], []];
        }

        $elevated = [];
        $low = [];

        foreach ($rules as $rule) {
            // Only phylum metrics actually present in this report's data.
            if (! array_key_exists($rule->metric, $phylumData)) {
                continue;
            }

            $value = (float) $phylumData[$rule->metric];

            if (! $rule->matches($value)) {
                continue;
            }

            $direction = match ($rule->operator) {
                'gt', 'gte' => 'high',
                'lt', 'lte' => 'low',
                'outside' => $value > (float) $rule->value2 ? 'high' : ($value < (float) $rule->value ? 'low' : null),
                default => null, // 'between' = within range, not a finding
            };

            if ($direction === null) {
                continue;
            }

            $bucket = $direction === 'high' ? 'elevated' : 'low';
            $taxon = $rule->metric;

            $entry = (${$bucket}[$taxon] ?? null) ?: [
                'taxon' => $taxon,
                'value' => self::formatPercent($value),
                'notes' => [],
            ];
            $entry['notes'][] = $rule->trigger_name;
            ${$bucket}[$taxon] = $entry;
        }

        $finalise = fn (array $items): array => array_values(array_map(fn ($e) => [
            'taxon' => $e['taxon'],
            'value' => $e['value'],
            'note' => implode(', ', array_unique($e['notes'])),
        ], $items));

        return [$finalise($elevated), $finalise($low)];
    }

    protected static function formatPercent(float $value): string
    {
        return ($value == (int) $value ? (string) (int) $value : (string) round($value, 1)) . '%';
    }
}
