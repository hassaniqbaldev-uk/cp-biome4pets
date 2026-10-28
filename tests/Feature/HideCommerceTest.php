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
 * "No commerce" plan display (hide_commerce): shows the plan (intro, steps, stage
 * labels, products, durations) but hides every commercial element — subscribe panel,
 * subscribe buttons, per-product prices, discount lines, buy links, the "Optional
 * add-on" tag, the subscription clause/CTA (PDF), the Klaviyo price/url, and the
 * subscribe route. INDEPENDENT of and mutually exclusive with hide_subscribe (which
 * hides the whole plan section). Default false → existing reports unchanged.
 */
class HideCommerceTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function makeReport(bool $hideSubscribe = false, bool $hideCommerce = false, bool $excludeIncluded = false): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Lola']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => '4481-BB', 'sample_id' => '4481-BB',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 40], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create([
            'key' => 'reset-recover-'.uniqid(), 'name' => 'Reset & Recover', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/checkout',
            'subscription_price' => '£29.75 / month',
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan->id, 'status' => 'published', 'pet_snapshot' => ['name' => 'Lola'],
            'plan_intro' => 'Lola\'s gut shows an imbalance; this plan restores balance.',
            'hide_subscribe' => $hideSubscribe, 'hide_commerce' => $hideCommerce,
            'subscription_snapshot' => [
                'available' => true, 'price' => '£29.75 / month', 'full_price' => '£35 / month',
                'saving_label' => '15% off', 'url' => 'https://loop.test/checkout',
                'includes' => [['name' => 'PetBiome AMR', 'price' => 35]],
            ],
        ]);

        // $amr stands in for the plan-INCLUDED product. Flagging it bb_excluded makes
        // it the "add-on" case (e.g. Gut Renew in Rebuild & Renew), which on a
        // no-commerce report reads "Available as an add-on" instead of "Included in plan".
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true, 'bb_excluded' => $excludeIncluded]);
        $kit = CatalogProduct::create(['name' => 'Retest Kit', 'price' => 180, 'subscription_discount_percent' => 30, 'is_active' => true, 'url' => 'https://shop.test/kit']);

        $step = ReportStep::create(['report_id' => $report->id, 'title' => 'Step 1: Microbiome Reset', 'type' => 'product', 'stage_label' => 'Phase 1 · Months 1-3', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'duration' => '3 months', 'inclusion' => 'included', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $kit->id, 'duration' => 'One-off', 'inclusion' => 'optional', 'position' => 1]);

        return $report->fresh();
    }

    private function web(Report $r): string
    {
        return $this->get('/report/'.$r->public_token)->assertOk()->getContent();
    }

    private function pdf(Report $r): string
    {
        return view('report.pdf', ['report' => $r->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct'])])->render();
    }

    // ── Baseline: both off → full plan WITH commerce (unchanged) ─────────────

    public function test_both_off_shows_plan_and_commerce(): void
    {
        $web = $this->web($this->makeReport());

        // Plan
        $this->assertStringContainsString('Step 1: Microbiome Reset', $web);
        $this->assertStringContainsString('restores balance', $web);
        $this->assertStringContainsString('Duration:', $web);
        // Commerce
        $this->assertStringContainsString('Subscribe to the', $web);     // panel heading
        $this->assertStringContainsString('Subscribe to plan', $web);    // panel button
        $this->assertStringContainsString('Ready to get started?', $web); // closing nudge
        $this->assertStringContainsString('Optional add-on', $web);
        $this->assertStringContainsString('on the subscription plan', $web);
        $this->assertStringContainsString('Buy individually', $web);
    }

    // ── hide_commerce ON → plan shows, commerce gone ─────────────────────────

    public function test_hide_commerce_shows_plan_but_no_commerce_web(): void
    {
        $web = $this->web($this->makeReport(hideCommerce: true));

        // Plan STILL shows.
        $this->assertStringContainsString('<h2 class="text-lg font-bold tracking-tight">Recommended Next Steps</h2>', $web);
        $this->assertStringContainsString('Step 1: Microbiome Reset', $web);
        $this->assertStringContainsString('Phase 1 · Months 1-3', $web);
        $this->assertStringContainsString('restores balance', $web);   // plan intro
        $this->assertStringContainsString('Duration:', $web);
        $this->assertStringContainsString('PetBiome AMR', $web);        // product name
        $this->assertStringContainsString('Included in plan', $web);    // plan membership (kept)

        // Commerce GONE.
        $this->assertStringNotContainsString('Subscribe to the', $web);
        $this->assertStringNotContainsString('Subscribe to plan', $web);
        $this->assertStringNotContainsString('Ready to get started?', $web);
        $this->assertStringNotContainsString('Optional add-on', $web);
        $this->assertStringNotContainsString('on the subscription plan', $web);
        $this->assertStringNotContainsString('Buy individually', $web);
        $this->assertStringNotContainsString('£29.75 / month', $web);
        $this->assertStringNotContainsString('/report/'.$this->makeReport(hideCommerce: true)->public_token.'/subscribe', $web);
    }

    public function test_hide_commerce_pdf_shows_plan_without_subscription_clause_or_cta(): void
    {
        $pdf = $this->pdf($this->makeReport(hideCommerce: true));

        $this->assertStringContainsString('Reset &amp; Recover', $pdf);           // plan name in intro
        $this->assertStringContainsString('personalised protocol', $pdf);          // intro present
        $this->assertStringNotContainsString('available as a subscription from', $pdf);
        $this->assertStringNotContainsString('View plan online', $pdf);
        $this->assertStringNotContainsString('full plan &amp; subscription', $pdf);
    }

    // ── hide_subscribe ON → whole plan section hidden (unchanged behaviour) ──

    public function test_hide_subscribe_hides_the_whole_plan_section(): void
    {
        $web = $this->web($this->makeReport(hideSubscribe: true));

        // The whole plan section is gone: no heading markup, no steps, no commerce,
        // no plan intro. ("Recommended Next Steps" also appears in a CSS comment, so
        // assert on the <h2> markup rather than the bare phrase.)
        $this->assertStringNotContainsString('<h2 class="text-lg font-bold tracking-tight">Recommended Next Steps</h2>', $web);
        $this->assertStringNotContainsString('Step 1: Microbiome Reset', $web);
        $this->assertStringNotContainsString('restores balance', $web);
        $this->assertStringNotContainsString('Subscribe to plan', $web);
    }

    // ── Klaviyo payload ──────────────────────────────────────────────────────

    public function test_klaviyo_omits_subscription_price_and_url_when_hide_commerce(): void
    {
        $props = $this->makeReport(hideCommerce: true)->klaviyoPlanProperties();
        $this->assertTrue($props['has_plan']);
        $this->assertSame('Reset & Recover', $props['plan_name']);     // metadata kept
        $this->assertNull($props['subscription_price']);
        $this->assertNull($props['subscription_url']);

        // Commerce report still emits them.
        $std = $this->makeReport()->klaviyoPlanProperties();
        $this->assertSame('£29.75 / month', $std['subscription_price']);
        $this->assertSame('https://loop.test/checkout', $std['subscription_url']);
    }

    // ── Subscribe route ──────────────────────────────────────────────────────

    public function test_subscribe_route_redirects_for_hide_commerce_but_works_otherwise(): void
    {
        $commerce = $this->makeReport();
        $this->get('/report/'.$commerce->public_token.'/subscribe')->assertOk();

        $noCommerce = $this->makeReport(hideCommerce: true);
        $this->get('/report/'.$noCommerce->public_token.'/subscribe')
            ->assertRedirect(route('report.show', ['token' => $noCommerce->public_token]));
    }

    // ── Mutual exclusivity (model saving hook) ───────────────────────────────

    public function test_both_true_resolves_to_hide_subscribe_wins(): void
    {
        $r = $this->makeReport();
        $r->hide_subscribe = true;
        $r->hide_commerce = true;
        $r->save();

        $r->refresh();
        $this->assertTrue($r->hide_subscribe);
        $this->assertFalse($r->hide_commerce);   // cleared by the saving hook
    }

    public function test_defaults_false_so_existing_reports_are_unchanged(): void
    {
        $r = $this->makeReport();
        $this->assertFalse($r->hide_commerce);
        $this->assertFalse($r->hide_subscribe);
    }

    // ── Part A: "Your Biome Balance Programme" reassurance box ───────────────

    public function test_box_shows_only_on_hide_commerce_web(): void
    {
        $on = $this->web($this->makeReport(hideCommerce: true));
        $this->assertStringContainsString('Your Biome Balance Programme', $on);
        $this->assertStringContainsString('Included programme', $on);   // the pill
        $this->assertStringContainsString('already included in your package', $on);
        $this->assertStringContainsString('Dr Carol Hughes', $on);

        $off = $this->web($this->makeReport());
        $this->assertStringNotContainsString('Your Biome Balance Programme', $off);
    }

    public function test_box_shows_only_on_hide_commerce_pdf(): void
    {
        $on = $this->pdf($this->makeReport(hideCommerce: true));
        $this->assertStringContainsString('Your Biome Balance Programme', $on);
        $this->assertStringContainsString('Included programme', $on);   // the pill
        $this->assertStringContainsString('sent to you automatically', $on);

        $off = $this->pdf($this->makeReport());
        $this->assertStringNotContainsString('Your Biome Balance Programme', $off);
    }

    // ── Part B: "Available as an add-on" label for bb_excluded products ──────

    public function test_bb_excluded_product_shows_addon_label_on_hide_commerce_report(): void
    {
        $web = $this->web($this->makeReport(hideCommerce: true, excludeIncluded: true));

        // The flagged (included-but-excluded) product reads as an add-on, NOT "Included":
        // an amber pill by the name + an "available separately" explainer box.
        $this->assertStringContainsString('Available as an add-on', $web);
        $this->assertStringContainsString('Available separately if you\'d like to add it.', $web);
        $this->assertStringNotContainsString('Included in plan', $web);
    }

    public function test_bb_excluded_product_is_unchanged_on_a_standard_report(): void
    {
        // Same flagged product, but hide_commerce is false (standard report): it must
        // render exactly as before — "Included in plan", no add-on label.
        $web = $this->web($this->makeReport(excludeIncluded: true));

        $this->assertStringContainsString('Included in plan', $web);
        $this->assertStringNotContainsString('Available as an add-on', $web);
    }

    public function test_bb_excluded_is_ignored_without_hide_commerce_even_with_flag(): void
    {
        // Flag set but neither hide mode → the whole plan + commerce show normally and
        // the product keeps its "Included in plan" tag (flag is inert off BB reports).
        $web = $this->web($this->makeReport(excludeIncluded: true));
        $this->assertStringContainsString('Subscribe to plan', $web);
        $this->assertStringContainsString('Included in plan', $web);
    }

    public function test_unflagged_product_still_reads_included_on_hide_commerce_report(): void
    {
        // hide_commerce ON but product NOT flagged → still "Included in plan".
        $web = $this->web($this->makeReport(hideCommerce: true));
        $this->assertStringContainsString('Included in plan', $web);
        $this->assertStringNotContainsString('Available as an add-on', $web);
    }
}
