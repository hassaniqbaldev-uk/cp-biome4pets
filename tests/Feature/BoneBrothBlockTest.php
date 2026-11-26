<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Support\BoneBroth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The customer-facing Boil & Broth bone broth block, now sourced from the product
 * CATALOGUE (marker column catalog_products.bone_broth_flavour), not Settings.
 * Visibility is driven by App\Support\BoneBroth::forReport() and shared by web + PDF:
 * show ONLY when dysbiosis is Low, no bone broth is already in the plan (marker), and
 * the picked flavour's catalog product exists with a url. The report's own
 * bone_broth_flavour (the AI pick, Step A) is untouched and still drives the
 * recommendation line + preselected tab. First test and retest alike.
 */
class BoneBrothBlockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private const CHICKEN_URL = 'https://boilandbroth.example/chicken';

    private const BEEF_URL = 'https://boilandbroth.example/beef';

    private const WORDING_SNIPPET = 'Our collaborative canine microbiome study with Aberystwyth University';

    /** Create a marked, active bone broth catalog product. */
    private function makeProduct(string $flavour, string $url, ?string $price = '39.99'): CatalogProduct
    {
        return CatalogProduct::create([
            'name' => ucfirst($flavour).' Bone Broth', 'is_active' => true,
            'bone_broth_flavour' => $flavour, 'url' => $url, 'price' => $price,
        ]);
    }

    private function makeBothProducts(): void
    {
        $this->makeProduct('chicken', self::CHICKEN_URL);
        $this->makeProduct('beef', self::BEEF_URL);
    }

    /**
     * Build a report. dysbiosis 0.1 → banded "Low" (< 0.2); diet copy set so the diet
     * section (which hosts the block) renders. Optionally a retest.
     */
    private function makeReport(string $flavour, float $dysbiosis = 0.1, bool $retest = false, string $petName = 'Teddy'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => $petName]);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'dysbiosis_score' => $dysbiosis, 'csv_data' => ['phylum_totals' => ['Firmicutes' => 45]],
        ]);
        $attrs = [
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => 'published',
            'pet_snapshot' => ['name' => $petName], 'dysbiosis_score' => $dysbiosis,
            'ai_diet_include' => 'Include copy.', 'ai_diet_avoid' => 'Avoid copy.',
            'bone_broth_flavour' => $flavour,
        ];
        if ($retest) {
            $orig = Report::create(array_merge($attrs, ['bone_broth_flavour' => 'either']));
            $attrs['retest_of_report_id'] = $orig->id;
        }

        return Report::create($attrs)->fresh();
    }

    private function render(Report $report, string $view): string
    {
        return view($view, ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    // ── Web: the three flavours, sourced from the catalogue ─────────────────

    public function test_chicken_renders_with_switcher_product_price_and_new_tab_button(): void
    {
        $this->makeBothProducts();
        $html = $this->render($this->makeReport('chicken'), 'report.show');

        $this->assertStringContainsString(self::WORDING_SNIPPET, $html);
        $this->assertStringContainsString('We recommend chicken for Teddy.', $html);
        // Switcher preselects chicken.
        $this->assertStringContainsString('data-bb-selected="chicken"', $html);
        // Price comes from the catalog product.
        $this->assertStringContainsString('39.99', $html);
        // New-tab buy button → the chicken product's own url.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(self::CHICKEN_URL, '/').'"[^>]*target="_blank"[^>]*rel="noopener noreferrer"/',
            $html,
        );
    }

    public function test_beef_preselects_beef_and_links_the_beef_product_url(): void
    {
        $this->makeBothProducts();
        $html = $this->render($this->makeReport('beef'), 'report.show');

        $this->assertStringContainsString('We recommend beef for Teddy.', $html);
        $this->assertStringContainsString('data-bb-selected="beef"', $html);
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(self::BEEF_URL, '/').'"[^>]*target="_blank"/',
            $html,
        );
    }

    public function test_price_is_taken_from_the_product_not_a_constant(): void
    {
        $this->makeProduct('chicken', self::CHICKEN_URL, price: '29.50');
        $this->makeProduct('beef', self::BEEF_URL);
        $html = $this->render($this->makeReport('chicken'), 'report.show');

        $this->assertStringContainsString('29.50', $html);
    }

    public function test_either_shows_neutral_line_and_preselects_neither(): void
    {
        $this->makeBothProducts();
        $html = $this->render($this->makeReport('either'), 'report.show');

        $this->assertStringContainsString('Chicken and beef both suit Teddy.', $html);
        $this->assertStringContainsString('data-bb-selected=""', $html);
        $this->assertStringNotContainsString('data-bb-selected="chicken"', $html);
        // Both products' urls present.
        $this->assertStringContainsString(self::CHICKEN_URL, $html);
        $this->assertStringContainsString(self::BEEF_URL, $html);
        // Neither tab button is preselected.
        $this->assertStringNotContainsString('role="tab" aria-selected="true"', $html);
    }

    public function test_either_shows_only_the_flavours_whose_product_exists(): void
    {
        // Only a chicken product exists.
        $this->makeProduct('chicken', self::CHICKEN_URL);
        $html = $this->render($this->makeReport('either'), 'report.show');

        $this->assertStringContainsString(self::WORDING_SNIPPET, $html);
        $this->assertStringContainsString(self::CHICKEN_URL, $html);
        $this->assertStringNotContainsString('data-flavour="beef"', $html);
    }

    // ── Visibility gates ────────────────────────────────────────────────────

    public function test_block_absent_when_dysbiosis_is_not_low(): void
    {
        $this->makeBothProducts();
        // 0.35 → banded "Healthy" (0.2–0.5), not Low.
        $report = $this->makeReport('chicken', dysbiosis: 0.35);

        foreach (['report.show', 'report.pdf'] as $view) {
            $this->assertStringNotContainsString(self::WORDING_SNIPPET, $this->render($report, $view), $view);
        }
    }

    public function test_block_absent_when_the_picked_flavours_product_is_missing(): void
    {
        // Chicken recommended but only a beef product exists → hidden (web + PDF).
        $this->makeProduct('beef', self::BEEF_URL);
        $report = $this->makeReport('chicken');

        foreach (['report.show', 'report.pdf'] as $view) {
            $this->assertStringNotContainsString(self::WORDING_SNIPPET, $this->render($report, $view), $view);
        }
    }

    public function test_block_absent_when_no_bone_broth_products_exist(): void
    {
        $report = $this->makeReport('either');   // no products created at all

        foreach (['report.show', 'report.pdf'] as $view) {
            $this->assertStringNotContainsString(self::WORDING_SNIPPET, $this->render($report, $view), $view);
        }
    }

    public function test_block_absent_when_a_product_has_no_url(): void
    {
        // Chicken product exists but has no url → not buyable → chicken pick hides block.
        $this->makeProduct('chicken', '');
        $report = $this->makeReport('chicken');

        $this->assertStringNotContainsString(self::WORDING_SNIPPET, $this->render($report, 'report.show'));
    }

    public function test_block_hidden_when_plan_already_includes_a_marked_bone_broth_product(): void
    {
        $this->makeBothProducts();
        $report = $this->makeReport('chicken');

        // Attach a MARKED bone broth product to the report → in-plan → hidden.
        $inPlan = CatalogProduct::create(['name' => 'Some Broth', 'is_active' => true, 'bone_broth_flavour' => 'beef', 'url' => 'https://x.example/b']);
        $report->catalogProducts()->attach($inPlan->id, ['position' => 1]);

        foreach (['report.show', 'report.pdf'] as $view) {
            $this->assertStringNotContainsString(self::WORDING_SNIPPET, $this->render($report, $view), $view);
        }
    }

    // ── PDF: static version, no switcher/button ─────────────────────────────

    public function test_pdf_shows_static_version_without_switcher_or_button(): void
    {
        $this->makeBothProducts();
        $html = $this->render($this->makeReport('chicken'), 'report.pdf');

        $this->assertStringContainsString(self::WORDING_SNIPPET, $html);
        $this->assertStringContainsString('We recommend chicken for Teddy.', $html);
        // No switcher, no buy button, no outbound link on the PDF.
        $this->assertStringNotContainsString('Shop Now', $html);
        $this->assertStringNotContainsString('data-bb-selected', $html);
        $this->assertStringNotContainsString(self::CHICKEN_URL, $html);
    }

    // ── First test AND retest both follow the rules ─────────────────────────

    public function test_retest_report_also_renders_the_block_when_gates_pass(): void
    {
        $this->makeBothProducts();
        $retest = $this->makeReport('chicken', retest: true);
        $this->assertNotNull($retest->retest_of_report_id);

        $html = $this->render($retest, 'report.show');
        $this->assertStringContainsString(self::WORDING_SNIPPET, $html);
        $this->assertStringContainsString('data-bb-selected="chicken"', $html);
    }

    // ── The report's own bone_broth_flavour (Step A) is untouched ───────────

    public function test_report_bone_broth_flavour_is_untouched_and_drives_the_block(): void
    {
        $this->makeBothProducts();
        $report = $this->makeReport('beef');

        $this->render($report, 'report.show');

        // The AI pick column is unchanged (read-only) and still drives the output.
        $this->assertSame('beef', $report->fresh()->bone_broth_flavour);
        $payload = BoneBroth::forReport($report->fresh());
        $this->assertSame('beef', $payload['flavour']);
        $this->assertSame('beef', $payload['selected']);
    }

    // ── Unit: product lookup, marker gate, helpers ──────────────────────────

    public function test_product_lookup_by_marker_is_deterministic_and_active_only(): void
    {
        // Inactive marked product is ignored.
        CatalogProduct::create(['name' => 'Old chicken', 'is_active' => false, 'bone_broth_flavour' => 'chicken', 'url' => 'https://x/inactive']);
        $this->assertNull(BoneBroth::product('chicken'));

        // Two active chicken markers → first by id wins (deterministic).
        $first = $this->makeProduct('chicken', 'https://x/first');
        $this->makeProduct('chicken', 'https://x/second');
        $this->assertSame($first->id, BoneBroth::product('chicken')->id);
    }

    public function test_plan_includes_bone_broth_detected_by_marker(): void
    {
        $this->makeBothProducts();
        $report = $this->makeReport('chicken');
        $this->assertFalse(BoneBroth::planIncludesBoneBroth($report->fresh()));

        // A normal (unmarked) attached product does NOT trigger the hide.
        $normal = CatalogProduct::create(['name' => 'Gut Renew', 'is_active' => true]);
        $report->catalogProducts()->attach($normal->id, ['position' => 1]);
        $this->assertFalse(BoneBroth::planIncludesBoneBroth($report->fresh()));

        // A MARKED attached product does.
        $marked = CatalogProduct::create(['name' => 'Anything', 'is_active' => true, 'bone_broth_flavour' => 'chicken']);
        $report->catalogProducts()->attach($marked->id, ['position' => 2]);
        $this->assertTrue(BoneBroth::planIncludesBoneBroth($report->fresh()));
    }

    public function test_forReport_gate_combinations(): void
    {
        $this->makeBothProducts();

        // All gates pass → payload.
        $this->assertNotNull(BoneBroth::forReport($this->makeReport('beef')));

        // Dysbiosis not low → null.
        $this->assertNull(BoneBroth::forReport($this->makeReport('beef', dysbiosis: 0.9)));
    }

    public function test_recommendation_line_wording(): void
    {
        $this->assertSame(
            'We recommend chicken for Rex. For the entire 6 month programme. Please only feed one week after introducing the supplements.',
            BoneBroth::recommendationLine('chicken', 'Rex'),
        );
        $this->assertSame(
            'Chicken and beef both suit Rex. Choose whichever your dog prefers. For the entire 6 month programme. Please only feed one week after introducing the supplements.',
            BoneBroth::recommendationLine('either', 'Rex'),
        );
    }
}
