<?php

namespace App\Support;

use App\Models\Report;

/**
 * Assembles the GROUNDED facts payload for a retest's AI summary, and the allow-list of
 * taxa the AI may name — both derived ENTIRELY from the report's already-frozen data
 * (Report::keyChanges(), the specialist recovery rows, the retest_comparison overview +
 * major-group phyla, the suggested plan, and the pet's stored health notes). Nothing is
 * recomputed from CSVs here; this only reshapes existing accessors into prose facts.
 *
 * The allow-list is the SAME mechanism as the diet-guidance grounding
 * (ReportGeneration::groundedTaxaNames): the AI may name only these organisms, and the
 * validator's unknown-taxon scan whitelists exactly this set — so prompt and guardrail
 * can never drift.
 */
class RetestSummaryFacts
{
    /** % threshold for a phylum to count as a "major group" (matches the report view). */
    public const MAJOR_GROUP_MIN_PCT = 1.0;

    /**
     * The grounded facts text + the allow-list of nameable taxa for a retest.
     *
     * @return array{facts: string, allow_list: array<int, string>}
     */
    public static function build(Report $report): array
    {
        return [
            'facts' => self::facts($report),
            'allow_list' => self::allowList($report),
        ];
    }

    /**
     * Every organism the report already names — key changes + the six specialists +
     * the major-group phyla — de-duplicated case-insensitively (first spelling wins).
     * This is the ONLY set the AI summary may name.
     *
     * @return array<int, string>
     */
    public static function allowList(Report $report): array
    {
        $comparison = is_array($report->retest_comparison) ? $report->retest_comparison : [];
        $names = [];

        // Key changes (the team's edited copy, or the default).
        $keyChanges = $report->keyChanges() ?? ['increases' => [], 'decreases' => []];
        foreach (['increases', 'decreases'] as $side) {
            foreach ($keyChanges[$side] ?? [] as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        // The six specialist genera (named even when not detected — the report tracks them).
        foreach (ReportContent::specialistRecovery($comparison) as $sp) {
            $name = trim((string) ($sp['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        // Major-group phyla (present at >= 1% in either test — as the view selects them).
        foreach ($comparison['taxa']['phylum'] ?? [] as $phylum) {
            $original = (float) ($phylum['original_pct'] ?? 0);
            $retest = (float) ($phylum['retest_pct'] ?? 0);
            if ($original >= self::MAJOR_GROUP_MIN_PCT || $retest >= self::MAJOR_GROUP_MIN_PCT) {
                $name = trim((string) ($phylum['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        // Case-insensitive de-dupe, first spelling wins.
        $seen = [];
        $out = [];
        foreach ($names as $name) {
            $lower = strtolower($name);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $out[] = $name;
        }

        return $out;
    }

    /** The frozen facts, as a readable block the prompt pastes verbatim. */
    protected static function facts(Report $report): string
    {
        $comparison = is_array($report->retest_comparison) ? $report->retest_comparison : [];
        $sections = [];

        // Overview: diversity / species richness / F:B ratio, original -> retest.
        $overview = $comparison['overview'] ?? [];
        $o = $overview['original'] ?? [];
        $r = $overview['retest'] ?? [];
        if ($o !== [] || $r !== []) {
            $lines = ['OVERVIEW (original -> retest):'];
            $lines[] = '- Diversity (Shannon): '.self::num($o['diversity'] ?? null).' -> '.self::num($r['diversity'] ?? null);
            $lines[] = '- Species detected: '.self::num($o['species_richness'] ?? null).' -> '.self::num($r['species_richness'] ?? null);
            $lines[] = '- Firmicutes:Bacteroidetes ratio: '.self::num($o['firmicutes_bacteroidetes_ratio'] ?? null).' -> '.self::num($r['firmicutes_bacteroidetes_ratio'] ?? null);
            $sections[] = implode("\n", $lines);
        }

        // Major-group phyla.
        $phylumLines = [];
        foreach ($comparison['taxa']['phylum'] ?? [] as $phylum) {
            $op = (float) ($phylum['original_pct'] ?? 0);
            $rp = (float) ($phylum['retest_pct'] ?? 0);
            if ($op >= self::MAJOR_GROUP_MIN_PCT || $rp >= self::MAJOR_GROUP_MIN_PCT) {
                $phylumLines[] = '- '.trim((string) ($phylum['name'] ?? '')).': '.self::num($op).'% -> '.self::num($rp).'%';
            }
        }
        if ($phylumLines !== []) {
            $sections[] = "MAJOR GROUPS (phyla, original -> retest):\n".implode("\n", $phylumLines);
        }

        // The six specialists with their status (deterministic — the AI must not re-decide).
        $specialistLines = [];
        foreach (ReportContent::specialistRecovery($comparison) as $sp) {
            $specialistLines[] = '- '.trim((string) ($sp['name'] ?? '')).': '
                .self::num($sp['original_pct'] ?? null).'% -> '.self::num($sp['retest_pct'] ?? null).'% '
                .'(target '.self::num($sp['target_low'] ?? null).'-'.self::num($sp['target_high'] ?? null).'%, '
                .'status: '.trim((string) ($sp['status']['label'] ?? '')).')';
        }
        if ($specialistLines !== []) {
            $sections[] = "SPECIALIST RECOVERY (status is already decided — do not change it):\n".implode("\n", $specialistLines);
        }

        // Key changes (already decided labels).
        $keyChanges = $report->keyChanges() ?? ['increases' => [], 'decreases' => []];
        $kcLines = [];
        foreach ([['New priorities (increases)', 'increases'], ['Improved (decreases)', 'decreases']] as [$heading, $side]) {
            $rows = $keyChanges[$side] ?? [];
            if ($rows === []) {
                continue;
            }
            $kcLines[] = $heading.':';
            foreach ($rows as $row) {
                $kcLines[] = '  - '.trim((string) ($row['name'] ?? '')).': '
                    .self::num($row['original_pct'] ?? null).'% -> '.self::num($row['retest_pct'] ?? null).'% '
                    .'('.trim((string) ($row['label'] ?? '')).')';
            }
        }
        if ($kcLines !== []) {
            $sections[] = "KEY CHANGES (labels are already decided):\n".implode("\n", $kcLines);
        }

        // Suggested plan + how it changed from the original (reuses the existing accessors).
        $planLines = [];
        $planName = $report->plan?->name;
        if (filled($planName)) {
            $planLines[] = '- Suggested plan: '.$planName;
        }
        $planChange = $report->planChangeLine();
        if (filled($planChange)) {
            $planLines[] = '- '.$planChange;
        }
        if ($planLines !== []) {
            $sections[] = "SUGGESTED PLAN:\n".implode("\n", $planLines);
        }

        // Owner-reported health notes (context only — same source other prompts use).
        $notes = trim((string) ($report->pet?->healthNotesForContext($report->report_date) ?? ''));
        if ($notes !== '') {
            $sections[] = "OWNER-REPORTED HEALTH NOTES (context only, not clinical fact):\n".$notes;
        }

        return implode("\n\n", $sections);
    }

    /** Trim a numeric fact for display; blank for null. Reuses ReportContent's formatter. */
    protected static function num(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return is_numeric($value) ? ReportContent::num((float) $value) : trim((string) $value);
    }
}
