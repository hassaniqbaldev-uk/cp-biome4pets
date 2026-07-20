<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Test;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UTM tagging applied to report-surface links and email links, plus the safety
 * properties: routes still resolve when UTMs are present, and the Loop checkout
 * URL is left clean.
 */
class UtmTaggingTest extends TestCase
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

    private function makeReport(): Report
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $plan = Plan::create([
            'key' => 'restore-rebalance', 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/checkout/CLEAN',
            'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit', 'diet' => 'Kibble']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-U', 'sample_id' => 'ORD-U',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            // Kibble + Imbalanced → the nutritionist diet-review block renders, so its
            // UTM-tagged link is present to assert on (the block now shows only for
            // kibble + Imbalanced / Imbalanced & Depleted).
            'microbiome_classification' => 'Imbalanced',
            'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'plan_id' => $plan->id, 'status' => 'published',
            'pet_snapshot' => ['name' => 'Biscuit', 'diet' => 'Kibble'],
            'subscription_snapshot' => ['available' => true, 'price' => '£29.75 / month', 'url' => 'https://loop.test/checkout/CLEAN', 'includes' => []],
        ]);
        ReportStep::create(['report_id' => $report->id, 'title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    public function test_report_surface_links_carry_report_utms(): void
    {
        $report = $this->makeReport();

        $html = $this->get('/report/'.$report->public_token)->assertOk()->getContent();

        // Subscribe CTA → interstitial, tagged.
        $this->assertStringContainsString('utm_campaign=subscribe', $html);
        // Nutritionist CTA (kibble diet) and shop link, tagged as report surface.
        $this->assertStringContainsString('utm_campaign=nutritionist', $html);
        $this->assertStringContainsString('utm_campaign=shop', $html);
        $this->assertStringContainsString('utm_medium=report', $html);
    }

    public function test_pdf_view_online_link_is_tagged(): void
    {
        $report = $this->makeReport();

        $html = view('report.pdf', ['report' => $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct'])])->render();

        $this->assertStringContainsString('utm_content=pdf_view_online', $html);
        $this->assertStringContainsString('utm_medium=report', $html);
    }

    public function test_token_route_still_resolves_with_utm_params_present(): void
    {
        $report = $this->makeReport();

        // UTMs in the query must not interfere with token resolution (path-based).
        $this->get('/report/'.$report->public_token.'?utm_source=biome4pets_app&utm_medium=report&utm_campaign=test')
            ->assertOk()
            ->assertSee('Biscuit');

        $this->get('/report/'.$report->public_token.'/subscribe?utm_source=biome4pets_app&utm_medium=report&utm_campaign=subscribe')
            ->assertOk();
    }

    public function test_loop_checkout_url_is_left_clean(): void
    {
        $report = $this->makeReport();

        $html = $this->get('/report/'.$report->public_token.'/subscribe')->assertOk()->getContent();

        // The Loop checkout target is the bare URL — no UTMs appended to it.
        $this->assertStringContainsString('https://loop.test/checkout/CLEAN', $html);
        $this->assertStringNotContainsString('loop.test/checkout/CLEAN?utm', $html);
    }

    public function test_password_reset_email_link_carries_email_utm(): void
    {
        // Resolve the reset URL deterministically (independent of the route).
        ResetPassword::createUrlUsing(fn ($notifiable, $token) => 'https://app.test/reset/'.$token.'?email=a%40b.com');

        $user = new User(['email' => 'a@b.com']);
        $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

        // The branded email renders a view; the tagged URL is its view data.
        $url = $mail->viewData['url'];
        $this->assertStringContainsString('utm_medium=email', $url);
        $this->assertStringContainsString('utm_campaign=password_reset', $url);
        // The existing token path + email query param survive the tagging.
        $this->assertStringContainsString('/reset/tok123', $url);
        $this->assertStringContainsString('email=a%40b.com', $url);

        ResetPassword::createUrlUsing(null);
    }
}
