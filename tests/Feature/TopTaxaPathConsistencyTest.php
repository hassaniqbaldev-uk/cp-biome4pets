<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression guard for the genus/species feature. There are FOUR generation paths
 * and the original bug was that two of them (the admin UI buttons) built their own
 * $deterministic bundle and forgot to pass top_taxa, so the AI never received the
 * bacteria to name. This test locks in that ALL FOUR paths:
 *   (a) feed top_taxa into the prompt (interpretationColumns' $deterministic), and
 *   (b) populate the validator's allowed-taxa whitelist (gradeAndLog 'species').
 * If a path is added or its wiring is dropped, this fails loudly.
 *
 * The four paths:
 *   1. ReportGeneration::createReportFromTest        (tag 'generate_from_test')
 *   2. ReportGeneration::regenerateReport            (tag 'bulk_regenerate')
 *   3. ReportResource "Generate from existing test"  (tag 'wizard_existing_test')
 *   4. ReportResource "Process CSV"                  (tag 'wizard_new_csv')
 */
class TopTaxaPathConsistencyTest extends TestCase
{
    private function source(string $relative): string
    {
        return file_get_contents(base_path($relative));
    }

    public function test_all_four_generation_paths_are_present(): void
    {
        $gen = $this->source('app/Support/ReportGeneration.php');
        $res = $this->source('app/Filament/Resources/ReportResource.php');

        $this->assertStringContainsString("'path' => 'generate_from_test'", $gen);
        $this->assertStringContainsString("'path' => 'bulk_regenerate'", $gen);
        $this->assertStringContainsString("'path' => 'wizard_existing_test'", $res);
        $this->assertStringContainsString("'path' => 'wizard_new_csv'", $res);
    }

    public function test_report_generation_paths_feed_top_taxa_and_whitelist(): void
    {
        $gen = $this->source('app/Support/ReportGeneration.php');

        // Both ReportGeneration paths read top_taxa from the stored test CSV…
        $this->assertSame(
            2,
            substr_count($gen, "'top_taxa' => \$test->csv_data['top_taxa'] ?? []"),
            'Both ReportGeneration paths must feed top_taxa into $deterministic.',
        );
        // …and both pass the names as the validator whitelist.
        $this->assertSame(
            2,
            substr_count($gen, "'species' => self::taxaNames(\$deterministic['top_taxa']"),
            'Both ReportGeneration paths must populate the allowed-taxa whitelist.',
        );
    }

    public function test_admin_ui_paths_feed_top_taxa_and_whitelist(): void
    {
        $res = $this->source('app/Filament/Resources/ReportResource.php');

        // "Generate from existing test" reads from the stored test; "Process CSV"
        // reads from the freshly-parsed $results — both wire top_taxa.
        $this->assertStringContainsString("'top_taxa' => \$test->csv_data['top_taxa'] ?? []", $res);
        $this->assertStringContainsString("'top_taxa' => \$results['top_taxa'] ?? []", $res);

        // Both admin paths populate the whitelist via the shared helper.
        $this->assertSame(
            2,
            substr_count($res, "'species' => ReportGeneration::taxaNames(\$deterministic['top_taxa'])"),
            'Both admin UI paths must populate the allowed-taxa whitelist.',
        );
    }
}
