<?php

namespace Tests\Unit;

use App\Support\AdminFormatting;
use PHPUnit\Framework\TestCase;

/**
 * #4 — every quality-flag code shown to a reviewer must have a plain-English
 * explanation (what happened / why / what to do), and the two newer codes
 * (unwell_no_plan, panel_contradiction) must have short labels too.
 */
class ReviewIssueExplanationTest extends TestCase
{
    public static function codes(): array
    {
        return array_map(fn ($c) => [$c], [
            'generation_failed', 'json_parse_failed', 'empty_output', 'bad_score_enum',
            'plan_unmatched', 'unwell_no_plan', 'manual_plan_review', 'panel_contradiction',
            'band_contradiction', 'number_contradiction', 'unknown_taxon', 'banned_phrase',
        ]);
    }

    /**
     * @dataProvider codes
     */
    public function test_every_code_has_a_label_and_a_substantial_explanation(string $code): void
    {
        $label = AdminFormatting::reviewIssueLabel($code);
        $this->assertNotSame($code, $label, "code {$code} should have a human label, not the raw code");

        $explanation = AdminFormatting::reviewIssueExplanation($code);
        $this->assertGreaterThan(40, strlen($explanation), "code {$code} should have a real explanation");
    }

    public function test_unwell_no_plan_explanation_guides_the_reviewer(): void
    {
        $text = AdminFormatting::reviewIssueExplanation('unwell_no_plan');

        $this->assertStringContainsString('imbalance', $text);                 // what happened
        $this->assertStringContainsString('no plan is currently selected', $text); // current-state why
        $this->assertStringContainsString('Choose an appropriate plan', $text);    // what to do
        $this->assertStringContainsString('Selecting a plan clears this flag', $text); // it self-resolves
    }

    public function test_manual_plan_review_explanation_frames_the_super_admin_check(): void
    {
        $text = AdminFormatting::reviewIssueExplanation('manual_plan_review');

        $this->assertStringContainsString('did not auto-match', $text);
        $this->assertStringContainsString('manually selected a plan', $text);
        $this->assertStringContainsString('Super Admin', $text);
    }

    public function test_unknown_code_has_no_explanation(): void
    {
        $this->assertSame('', AdminFormatting::reviewIssueExplanation('something_new'));
        $this->assertSame('something_new', AdminFormatting::reviewIssueLabel('something_new'));
    }
}
