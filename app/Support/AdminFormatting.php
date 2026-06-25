<?php

namespace App\Support;

/**
 * Shared presentation constants/helpers for the admin panel, so date formats and
 * status-badge colours/labels are defined once and reused everywhere (no local
 * drift across resources, relation managers and dashboard widgets).
 *
 * Presentation only — no behaviour.
 */
class AdminFormatting
{
    /** Readable date for table columns / infolists, e.g. "18 Jun 2026". */
    public const DATE = 'd M Y';

    /** Date + time for the few places a timestamp genuinely matters. */
    public const DATE_TIME = 'd M Y, H:i';

    /** Report status → badge colour. Draft = gray, Published = green, Sent = blue
     *  (the most-complete state: published AND delivered to the customer). */
    public static function reportColor(?string $status): string
    {
        return match ($status) {
            'sent' => 'info',
            'published' => 'success',
            default => 'gray',
        };
    }

    /** Report status → human label. */
    public static function reportLabel(?string $status): string
    {
        return ucfirst((string) $status);
    }

    /** Derived test state → badge colour (reported = done, awaiting = pending). */
    public static function testStateColor(bool $hasReport): string
    {
        return $hasReport ? 'success' : 'warning';
    }

    /** Derived test state → human label. The stored status column was dropped; a
     *  test simply has a report or is awaiting one (see Test::hasReport()). */
    public static function testStateLabel(bool $hasReport): string
    {
        return $hasReport ? 'Reported' : 'Awaiting report';
    }

    /**
     * Phase 3: quality-check issue code → human-readable label for the edit-page
     * "needs review" banner. Unknown codes fall back to the raw code.
     */
    public static function reviewIssueLabel(string $code): string
    {
        return match ($code) {
            'generation_failed' => 'AI generation failed (API or transport error)',
            'json_parse_failed' => 'AI response could not be read (invalid JSON)',
            'empty_output' => 'AI returned no content',
            'bad_score_enum' => 'A health score has an unexpected value',
            'plan_unmatched' => 'Product rules fired but no plan is selected',
            'unwell_no_plan' => 'Pet looks imbalanced but no plan is selected',
            'manual_plan_review' => 'Manual plan selected — needs Super Admin review',
            'panel_contradiction' => 'The report panels give conflicting signals',
            'band_contradiction' => 'A microbe level is described differently from its band',
            'number_contradiction' => 'A stated figure may not match the computed data',
            'unknown_taxon' => 'Mentions an organism not found in the sample',
            'banned_phrase' => 'Contains diagnosis or cure wording',
            default => $code,
        };
    }

    /**
     * Plain-English explanation for a quality-check flag, written for a
     * NON-technical reviewer: what happened, why it matters, and what to do.
     * Shown in the edit-page "needs review" banner beneath the short label.
     * Unknown codes return '' so the banner simply shows the label + code.
     */
    public static function reviewIssueExplanation(string $code): string
    {
        return match ($code) {
            'generation_failed' => "The AI couldn't generate this report's interpretation (an API or connection error), so the summary, scores and insights are missing. Check the OpenAI key and credits, then regenerate the report. Don't publish it as-is.",
            'json_parse_failed' => "The AI replied, but its response couldn't be read, so some or all of the written copy may be blank or incomplete. Regenerate the report; if it keeps happening, the AI settings may need attention.",
            'empty_output' => 'The AI ran but returned no content, so the interpretation, scores and insights are empty. Regenerate the report (check the OpenAI key and credits first), or fill the copy in manually if it stays empty.',
            'bad_score_enum' => 'One of the health-insight scores came back as something other than the allowed values (Low, Medium, High, Very High), so it may display oddly. Open the Health Scores section and set it to a valid value before publishing.',
            'plan_unmatched' => "The pet's results triggered one or more product rules, but no plan's conditions fully matched and no plan is currently selected. Choose a suitable plan manually (or adjust the trigger rules). Selecting a plan clears this flag.",
            'unwell_no_plan' => "This pet's results show an imbalance, but none of the plan rules matched it and no plan is currently selected. Choose an appropriate plan manually before publishing. Selecting a plan clears this flag.",
            'manual_plan_review' => "This pet's results did not auto-match any plan rule, and an admin has manually selected a plan. Nothing is wrong with the report, but a Super Admin should sanity-check that the manually-chosen plan is the right one before (or shortly after) publishing.",
            'panel_contradiction' => "The report's own panels disagree: the pet is classified as imbalanced or depleted, yet the diversity panel reads as healthy. The report may give a mixed message. Check that the results and wording make sense together before publishing.",
            'band_contradiction' => "The written interpretation describes a microbe's level in a way that contradicts the arithmetic of its band — for example calling a value 'within the normal range' when it is actually below the typical range. This is an exact check, not a guess. Re-read the highlighted microbe text and correct the level wording (or regenerate) so it matches the band before publishing.",
            'number_contradiction' => 'A number written in the AI text may not match the figure we calculated for this pet. This is an automated guess and is often a false alarm. Skim the highlighted text and confirm the figure reads correctly.',
            'unknown_taxon' => "The AI text appears to name a bacterium or organism that wasn't in this pet's data. This is an automated guess and is often a false alarm (it can misread ordinary words). Check the wording doesn't mention something we didn't detect.",
            'banned_phrase' => "The text may contain diagnosis or cure wording (for example 'diagnose' or 'cure'), which our reports avoid. This is an automated guess. Re-read the flagged copy and soften any clinical claims.",
            default => '',
        };
    }
}
