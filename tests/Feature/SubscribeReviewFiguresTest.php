<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Setting;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The subscribe interstitial shows a CUSTOMER COUNT ("Join 1,000+ Happy Pet
 * Owners"), not a star rating (client asked to show how many owners, not a review
 * score). The count is admin-editable in Settings (the controller reads it from the
 * settings table, falling back to the default so the page is never blank if unset).
 * The rating setting still exists but is no longer rendered as a rating.
 */
class SubscribeReviewFiguresTest extends TestCase
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
            'key' => 'restore', 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true,
            'subscription_url' => 'https://biome4pets.myshopify.com/a/loop_subscriptions/checkout/XYZ',
            'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

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

    public function test_subscribe_falls_back_to_default_customer_count_when_unset(): void
    {
        $report = $this->makeReport();

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee('Happy Pet Owners')   // customer-count framing
            ->assertSee('1,000+')             // Setting::REVIEW_COUNT_DEFAULT
            ->assertDontSee('4.9')            // rating is NOT shown
            ->assertDontSee('reviews')        // no star-rating / review-score framing
            ->assertDontSee('★', false);
    }

    public function test_subscribe_reads_edited_customer_count_from_settings(): void
    {
        // The rating setting still exists but must not surface; the COUNT is what shows.
        Setting::set(Setting::REVIEW_RATING, '4.7');
        Setting::set(Setting::REVIEW_COUNT, '2,500+');

        $report = $this->makeReport();

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee('2,500+')             // edited count surfaces
            ->assertDontSee('1,000+')         // default replaced
            ->assertDontSee('4.7');           // rating not rendered
    }
}
