<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Security fixes:
 *  A — public reports resolve only by the high-entropy public_token; the old
 *      guessable petname-sampleid slug path no longer resolves (enumeration
 *      oracle closed).
 *  B — every response (report, PDF, admin) carries X-Robots-Tag: noindex; the
 *      report HTML carries a noindex meta; robots.txt disallows all.
 */
class ReportUrlSecurityTest extends TestCase
{
    private const NOINDEX = 'noindex, nofollow, noarchive';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function makeReport(bool $withPlan = false): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'KMS734', 'sample_id' => 'KMS734',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45, 'Bacteroidetes' => 25],
            'diversity_score' => 2.4, 'csv_data' => ['phylum_totals' => []],
        ]);

        $planId = null;
        if ($withPlan) {
            $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
            $plan = Plan::create([
                'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => true,
                'subscription_available' => true, 'subscription_url' => 'https://loop.test/checkout/X',
                'subscription_price' => '£29.75 / month',
            ]);
            $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
            $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);
            $planId = $plan->id;
        }

        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $planId, 'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
            'subscription_snapshot' => $withPlan ? ['available' => true, 'price' => '£29.75 / month', 'url' => 'x', 'includes' => []] : null,
        ]);
        $report->steps()->create(['title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    // ───────────────────────── FIX A ─────────────────────────

    public function test_token_is_short_high_entropy_and_distinct_from_slug(): void
    {
        $report = $this->makeReport();

        // 16 alphanumeric chars: friendlier than the old 40, still ~95 bits of
        // entropy (62^16) — infeasible to enumerate, so the 200/404 oracle stays
        // closed (see the unknown-token 404 assertion below).
        $this->assertSame(Report::PUBLIC_TOKEN_LENGTH, strlen($report->public_token));
        $this->assertSame(16, strlen($report->public_token));
        $this->assertTrue(ctype_alnum($report->public_token), 'token must be URL-safe alphanumeric');

        $this->assertNotEmpty($report->slug);
        $this->assertNotSame($report->slug, $report->public_token);
        // Two reports get different tokens.
        $this->assertNotSame($report->public_token, $this->makeReport()->public_token);
    }

    public function test_legacy_40_char_tokens_still_resolve(): void
    {
        // Existing reports keep their original 40-char tokens (the VARCHAR(40)
        // column is unchanged); the route matches on the token value regardless of
        // length, so old URLs must keep working.
        $report = $this->makeReport();
        $legacy = \Illuminate\Support\Str::random(40);
        $report->forceFill(['public_token' => $legacy])->saveQuietly();

        $this->assertSame(40, strlen($legacy));
        $this->get('/report/'.$legacy)->assertOk()->assertSee('Biscuit');
        $this->get('/report/'.$legacy.'/pdf')->assertOk();
    }

    public function test_report_resolves_by_token_and_old_slug_path_404s(): void
    {
        $report = $this->makeReport();

        // The token URL works.
        $this->get('/report/'.$report->public_token)->assertOk()->assertSee('Biscuit');

        // The guessable slug path (petname-sampleid) no longer resolves — the
        // 200/404 enumeration oracle is closed.
        $this->get('/report/'.$report->slug)->assertNotFound();
        $this->get('/report/biscuit-kms734')->assertNotFound();
        $this->get('/report/notarealtoken')->assertNotFound();
    }

    public function test_pdf_and_subscribe_resolve_by_token_not_slug(): void
    {
        $report = $this->makeReport(withPlan: true);

        $this->get('/report/'.$report->public_token.'/pdf')->assertOk();
        $this->get('/report/'.$report->public_token.'/subscribe')->assertOk();

        $this->get('/report/'.$report->slug.'/pdf')->assertNotFound();
        $this->get('/report/'.$report->slug.'/subscribe')->assertNotFound();
    }

    public function test_report_url_accessor_uses_the_token(): void
    {
        $report = $this->makeReport();

        $this->assertSame(url('/report/'.$report->public_token), $report->report_url);
        $this->assertStringContainsString($report->public_token, $report->report_url);
    }

    // ───────────────────────── FIX B ─────────────────────────

    public function test_noindex_header_on_report_pdf_and_admin(): void
    {
        $report = $this->makeReport();

        $this->get('/report/'.$report->public_token)->assertHeader('X-Robots-Tag', self::NOINDEX);
        $this->get('/report/'.$report->public_token.'/pdf')->assertHeader('X-Robots-Tag', self::NOINDEX);
        $this->get('/admin/login')->assertHeader('X-Robots-Tag', self::NOINDEX);
    }

    public function test_report_html_carries_meta_noindex(): void
    {
        $report = $this->makeReport();

        $this->get('/report/'.$report->public_token)
            ->assertSee('name="robots"', false)
            ->assertSee(self::NOINDEX, false);
    }

    public function test_robots_txt_disallows_all(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow: /', $robots);
    }

    // ─────────────────────── FIX H3: security headers ───────────────────────

    public function test_baseline_security_headers_on_every_response(): void
    {
        $report = $this->makeReport();

        foreach (['/report/'.$report->public_token, '/report/'.$report->public_token.'/pdf', '/admin/login'] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }

    public function test_hsts_is_not_set_outside_production(): void
    {
        // Test env is not production → no HSTS (it would break local/staging http).
        $this->assertFalse(app()->isProduction());

        $this->get('/admin/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_csp_is_applied_to_report_pages_only(): void
    {
        $report = $this->makeReport(withPlan: true);

        $csp = $this->get('/report/'.$report->public_token)->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'report page should carry a CSP');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp); // Chart.js

        // Subscribe page is also a public HTML page → CSP applies.
        $this->assertNotNull(
            $this->get('/report/'.$report->public_token.'/subscribe')->headers->get('Content-Security-Policy')
        );

        // The admin panel must NOT get the strict report CSP (Livewire/Alpine).
        $this->get('/admin/login')->assertHeaderMissing('Content-Security-Policy');
    }
}
