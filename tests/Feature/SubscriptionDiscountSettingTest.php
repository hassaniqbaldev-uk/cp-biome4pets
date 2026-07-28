<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\CatalogProductResource\Pages\CreateCatalogProduct;
use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\Setting;
use App\Models\Test;
use App\Support\ReportContent;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The flat subscription loyalty discount is a single global Setting
 * (SUBSCRIPTION_DISCOUNT_PERCENT, default 15). It drives the per-product discounted
 * price on INCLUDED report cards (£130 → £110.50, £35 → £29.75) and the loyalty note,
 * so the two can never disagree. Optional add-ons keep their OWN per-product rate.
 * Price/rate are read live, so a change re-renders existing reports with no backfill.
 */
class SubscriptionDiscountSettingTest extends TestCase
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

    // ── Resolver: default / override / blank / out-of-range ──────────────────

    public function test_resolver_defaults_to_15_when_unset(): void
    {
        $this->assertSame(15.0, Setting::subscriptionDiscountPercent());
        $this->assertSame(15, Setting::SUBSCRIPTION_DISCOUNT_PERCENT_DEFAULT);
    }

    public function test_resolver_uses_a_stored_value(): void
    {
        Setting::set(Setting::SUBSCRIPTION_DISCOUNT_PERCENT, '20');
        $this->assertSame(20.0, Setting::subscriptionDiscountPercent());
    }

    public function test_resolver_falls_back_on_blank_or_out_of_range(): void
    {
        foreach (['', '   ', 'abc', '-5', '150'] as $bad) {
            Setting::set(Setting::SUBSCRIPTION_DISCOUNT_PERCENT, $bad);
            $this->assertSame(15.0, Setting::subscriptionDiscountPercent(), "bad value [{$bad}] must fall back");
        }
    }

    // ── Included card: per-product discounted price from the global rate ─────

    private function makeReport(CatalogProduct $product, string $inclusion, bool $subAvailable = true): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-'.uniqid(), 'sample_id' => 'S-'.uniqid(),
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $plan = Plan::create([
            'key' => 'p-'.uniqid(), 'name' => 'Rebuild & Renew', 'enabled' => true,
            'subscription_available' => true, 'subscription_url' => 'https://loop.test/x',
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'plan_id' => $plan->id, 'pet_snapshot' => ['name' => 'Biscuit'],
            'subscription_snapshot' => [
                'available' => $subAvailable, 'price' => '£29.75 / month', 'url' => 'https://loop.test/x', 'includes' => [],
            ],
        ]);
        $step = ReportStep::create([
            'report_id' => $report->id, 'title' => 'Step 1', 'type' => 'product', 'stage_label' => 'Phase 1', 'position' => 0,
        ]);
        $step->products()->create(['catalog_product_id' => $product->id, 'inclusion' => $inclusion, 'position' => 0]);

        return $report->fresh()->load(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct']);
    }

    public function test_included_gut_renew_shows_110_50_at_the_default_rate(): void
    {
        $gutRenew = CatalogProduct::create(['name' => 'Gut Renew', 'price' => 130, 'is_active' => true]);
        $web = view('report.show', ['report' => $this->makeReport($gutRenew, 'included')])->render();

        // Original single-line format; only the "on the plan" figure is the product's
        // own discounted price (130 × 0.85 = 110.50). No struck price / "% off" note.
        // (The full visible line is "£130.00 individually · £110.50 / month on the
        // subscription plan"; the price + "individually" straddle a </b> tag, so the
        // contiguous, changed portion is asserted here.)
        $this->assertStringContainsString('£110.50 / month on the subscription plan', $web);
        $this->assertStringContainsString('individually', $web);
        $this->assertStringNotContainsString('normally £', $web);
    }

    public function test_included_35_product_shows_29_75_at_the_default_rate(): void
    {
        $amr = CatalogProduct::create(['name' => 'PetBiome AMR', 'price' => 35, 'is_active' => true]);
        $web = view('report.show', ['report' => $this->makeReport($amr, 'included')])->render();

        $this->assertStringContainsString('£29.75 / month on the subscription plan', $web);   // 35 × 0.85
        $this->assertStringContainsString('individually', $web);
    }

    public function test_changing_the_global_rate_retro_applies_to_the_card(): void
    {
        Setting::set(Setting::SUBSCRIPTION_DISCOUNT_PERCENT, '20');
        $gutRenew = CatalogProduct::create(['name' => 'Gut Renew', 'price' => 130, 'is_active' => true]);
        $web = view('report.show', ['report' => $this->makeReport($gutRenew, 'included')])->render();

        $this->assertStringContainsString('£104.00 / month on the subscription plan', $web);   // 130 × 0.80
    }

    public function test_optional_addon_keeps_its_own_per_product_rate(): void
    {
        // Retest kit: optional, its own 30% — must NOT be overridden by the global 15%.
        $kit = CatalogProduct::create([
            'name' => 'PetBiome Gut Microbiome Test Kit', 'price' => 180,
            'subscription_discount_percent' => 30, 'is_active' => true,
        ]);
        $web = view('report.show', ['report' => $this->makeReport($kit, 'optional')])->render();

        $this->assertStringContainsString('£126', $web);                 // 180 × 0.70 (its own 30%)
        $this->assertStringContainsString('normally £180', $web);
        $this->assertStringContainsString('with the 6-month subscription discount (30% off)', $web);
        // The global-rate loyalty note is NOT applied to the optional add-on.
        $this->assertStringNotContainsString('subscription loyalty discount', $web);
    }

    public function test_plain_price_fallback_when_no_discount_applies(): void
    {
        // Rate 0 → no included discount → plain price, no discount note.
        Setting::set(Setting::SUBSCRIPTION_DISCOUNT_PERCENT, '0');
        $gutRenew = CatalogProduct::create(['name' => 'Gut Renew', 'price' => 130, 'is_active' => true]);
        $web = view('report.show', ['report' => $this->makeReport($gutRenew, 'included')])->render();

        $this->assertStringContainsString('£130.00', $web);
        $this->assertStringNotContainsString('on the subscription plan', $web);
    }

    // ── The loyalty prose reflects the setting ──────────────────────────────

    public function test_loyalty_note_reflects_the_setting(): void
    {
        $this->assertStringContainsString('15% off', ReportContent::dietReviewLoyaltyNote());
        $this->assertStringNotContainsString('10% off', ReportContent::dietReviewLoyaltyNote());

        Setting::set(Setting::SUBSCRIPTION_DISCOUNT_PERCENT, '20');
        $this->assertStringContainsString('20% off', ReportContent::dietReviewLoyaltyNote());
    }

    // ── Settings page: Super-Admin gate + save ──────────────────────────────

    public function test_setting_is_super_admin_only_and_saves(): void
    {
        $this->actingAs(new \App\Models\User(['name' => 'A', 'email' => 'a@cp.agency', 'role' => 'admin']));
        $this->assertFalse(Settings::canAccess());

        $this->actingAs(new \App\Models\User(['name' => 'S', 'email' => 's@cp.agency', 'role' => 'super_admin']));
        $this->assertTrue(Settings::canAccess());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Settings::class)
            ->assertFormFieldExists(Setting::SUBSCRIPTION_DISCOUNT_PERCENT, 'form')
            ->fillForm([Setting::SUBSCRIPTION_DISCOUNT_PERCENT => '20'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(20.0, Setting::subscriptionDiscountPercent());

        // Out-of-range is rejected by the field validation.
        Livewire::test(Settings::class)
            ->fillForm([Setting::SUBSCRIPTION_DISCOUNT_PERCENT => '150'])
            ->call('save')
            ->assertHasFormErrors([Setting::SUBSCRIPTION_DISCOUNT_PERCENT]);
    }

    // ── CHANGE 4: the price field accepts clean 2-dp currency ───────────────

    public function test_price_field_accepts_two_decimal_currency_values(): void
    {
        $this->actingAs(new \App\Models\User(['name' => 'S', 'email' => 's2@cp.agency', 'role' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (['110.50', '29.75', '130.00'] as $price) {
            Livewire::test(CreateCatalogProduct::class)
                ->fillForm(['name' => 'P-'.$price, 'price' => $price])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertEqualsWithDelta(110.50, (float) CatalogProduct::where('name', 'P-110.50')->value('price'), 0.001);
        $this->assertEqualsWithDelta(29.75, (float) CatalogProduct::where('name', 'P-29.75')->value('price'), 0.001);
    }
}
