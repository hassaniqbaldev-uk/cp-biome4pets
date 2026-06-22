<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The subscribe interstitial re-enables the "preparing your plan" auto-redirect:
 * a progress bar fills over 15s, then the page hands off to the LIVE plan's Loop
 * checkout (same tab). The CTA still navigates immediately on click. When the
 * server already redirected (no checkout URL), the interstitial never renders, so
 * the timer can never fire to a broken URL.
 */
class SubscribeAutoRedirectTest extends TestCase
{
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

    private const LIVE_URL = 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/LIVEXYZ';

    private function makeReport(?string $url = self::LIVE_URL): Report
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true, 'image_path' => 'https://img.test/amr.jpg']);

        $plan = Plan::create([
            'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => $url,
            'subscription_price' => '£29.75 / month', 'subscription_full_price' => '£35 / month',
            'subscription_saving_label' => '15% off',
        ]);
        $s1 = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1 · Months 1–3', 'position' => 0]);
        $s1->products()->create(['catalog_product_id' => $amr->id, 'duration' => '3 months', 'inclusion' => 'included', 'position' => 0]);

        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-R', 'sample_id' => 'ORD-R',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan->id, 'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'Step', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    public function test_interstitial_auto_redirects_to_the_live_checkout(): void
    {
        $report = $this->makeReport();

        $res = $this->get('/report/'.$report->public_token.'/subscribe');

        $res->assertOk()
            ->assertSee('window.location.href', false)          // a JS redirect is present
            ->assertSee(self::LIVE_URL, false)                  // targeting the live plan URL
            ->assertSee('15000', false);                        // over the 15-second window
    }

    public function test_progress_bar_renders_inside_the_card_with_status_copy(): void
    {
        $report = $this->makeReport();

        $html = $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee('prep-bar', false)                      // the progress bar fill
            ->assertSee('prep-track', false)                    // ...with a visible track
            ->assertSee("Preparing Biscuit's plan", false)      // integrated status copy
            ->getContent();

        // The bar lives INSIDE the white card, not as a fixed page-top hairline.
        $cardPos = strpos($html, 'sub-card');
        $barPos = strpos($html, 'id="prep-bar"');
        $this->assertNotFalse($barPos);
        $this->assertGreaterThan($cardPos, $barPos, 'progress bar should render inside the card');
        // The old fixed top-of-page bar is gone.
        $this->assertStringNotContainsString('position:fixed; top:0', $html);
    }

    public function test_cta_button_label_states_the_auto_redirect_and_is_clickable(): void
    {
        $report = $this->makeReport();

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            // The button label itself is now the auto-redirect statement (with an arrow).
            ->assertSee("You'll be redirected automatically", false)
            // It is the CTA anchor → clicking navigates straight to the live checkout.
            ->assertSee('id="sub-cta"', false)
            ->assertSee('href="'.self::LIVE_URL.'"', false);

        // The CTA anchor's visible label is the new statement, not the old verb.
        $html = $this->get('/report/'.$report->public_token.'/subscribe')->getContent();
        preg_match('/<a[^>]*id="sub-cta"[^>]*>(.*?)<\/a>/s', $html, $m);
        $this->assertNotEmpty($m, 'CTA anchor not found');
        $this->assertStringContainsString("You'll be redirected automatically", $m[1]);
        $this->assertStringNotContainsString('Start', $m[1]);
    }

    public function test_interstitial_respects_reduced_motion_but_still_redirects(): void
    {
        $report = $this->makeReport();

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee('prefers-reduced-motion', false)        // motion is conditional
            ->assertSee('window.location.href', false);         // redirect happens regardless
    }

    public function test_header_bar_removed_for_mobile_height(): void
    {
        $report = $this->makeReport();

        // The navy <header> logo bar is gone (compact back-link replaces it); the
        // footer logo remains.
        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertDontSee('<header', false)
            ->assertSee('Back to report');
    }

    public function test_no_url_server_redirects_so_interstitial_never_renders(): void
    {
        // Guard: with no checkout URL the controller 302s back to the report, so
        // the auto-redirect script is never delivered.
        $report = $this->makeReport(url: null);

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertRedirect('/report/'.$report->public_token);
    }
}
