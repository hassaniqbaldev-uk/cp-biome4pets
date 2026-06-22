<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feedbucket is a STAFF feedback widget. It must NOT render for public/customer
 * report viewers; it only loads for an authenticated admin previewing the report.
 */
class FeedbucketVisibilityTest extends TestCase
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
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-F', 'sample_id' => 'ORD-F',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
        $report->steps()->create(['title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    public function test_public_customer_report_does_not_load_feedbucket(): void
    {
        $report = $this->makeReport();

        // Unauthenticated (a customer) — the staff widget must be absent.
        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertDontSee('feedbucket', false);
    }

    public function test_authenticated_staff_previewing_the_report_sees_feedbucket(): void
    {
        $report = $this->makeReport();

        $this->actingAs(User::create([
            'name' => 'Staff', 'email' => 'staff@e.com',
            'role' => User::ROLE_ADMIN, 'password' => Hash::make('secret'),
        ]));

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('feedbucket', false);
    }

    public function test_subscribe_interstitial_hides_feedbucket_from_customers(): void
    {
        // Build a subscribable report so the subscribe route renders (it redirects
        // when there's no live plan + checkout URL).
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $plan = Plan::create([
            'key' => 'restore', 'name' => 'Restore & Rebalance', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/checkout/X',
            'subscription_price' => '£29.75 / month',
        ]);
        $step = $plan->steps()->create(['type' => 'product', 'step_title' => 'Step 1', 'stage_label' => 'Phase 1', 'position' => 0]);
        $step->products()->create(['catalog_product_id' => $amr->id, 'inclusion' => 'included', 'position' => 0]);

        $report = $this->makeReport();
        $report->update(['plan_id' => $plan->id]);

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertDontSee('feedbucket', false);
    }
}
