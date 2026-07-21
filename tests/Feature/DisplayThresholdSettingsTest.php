<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Support\HealthInsightRules;
use App\Support\ReportContent;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tier A of the classification-rules audit: three DISPLAY-ONLY scientific band
 * boundaries are now admin-editable in Settings —
 *   - DISPLAY_DIVERSITY_HIGH_MIN     → ReportContent::diversityHighMin()   (default 2.5)
 *   - DISPLAY_RICHNESS_HEALTHY_MIN   → ReportContent::richnessHealthyMin() (default 650)
 *   - HEALTH_INSIGHT_TARGET_TOLERANCE→ HealthInsightRules::targetTolerance() (default 0.25)
 *
 * They change only which BAND LABEL a report prints. They do NOT feed classify(),
 * plan routing or the nutritionist trigger, and the resolvers fall back to the code
 * constant for any unset/blank/out-of-range value, so behaviour is IDENTICAL to today
 * until someone edits a value. The Settings form validates numeric + sane range +
 * ordering (bands can't invert) and is Super-Admin only.
 */
class DisplayThresholdSettingsTest extends TestCase
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

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        // The Settings page also manages product trigger rules on save().
        Schema::create('product_rules', function ($table) {
            $table->id();
            $table->string('trigger_name');
            $table->string('metric');
            $table->string('operator');
            $table->decimal('value', 12, 4);
            $table->decimal('value2', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function actAsSuperAdmin(): void
    {
        $this->actingAs(new User(['name' => 'Admin', 'email' => 'admin@cp.agency', 'role' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ── Resolvers: unset → the code constant (identical behaviour) ───────────

    public function test_resolvers_return_the_code_constant_when_unset(): void
    {
        $this->assertSame(ReportContent::DIVERSITY_HIGH_MIN, ReportContent::diversityHighMin());
        $this->assertSame((float) ReportContent::RICHNESS_HEALTHY_MIN, ReportContent::richnessHealthyMin());
        $this->assertSame(HealthInsightRules::TARGET_TOLERANCE, HealthInsightRules::targetTolerance());
    }

    public function test_bands_and_legends_are_identical_to_the_constants_when_unset(): void
    {
        // Same boundary semantics as ReportContentBandsTest, proving nothing moved.
        $this->assertSame('Medium', ReportContent::diversityBand(2.5)['label']);
        $this->assertSame('High', ReportContent::diversityBand(2.51)['label']);
        $this->assertSame('Moderate', ReportContent::richnessBand(650)['label']);
        $this->assertSame('Healthy', ReportContent::richnessBand(651)['label']);

        $this->assertSame(['< 1.9', '1.9 - 2.5', '> 2.5'], array_column(ReportContent::diversityLegend(), 'range'));
        $this->assertSame(['< 400', '400 - 650', '> 650'], array_column(ReportContent::richnessLegend(), 'range'));
    }

    // ── Resolvers: a set value overrides, and moves the DISPLAY band ─────────

    public function test_setting_the_diversity_boundary_moves_the_band_and_legend(): void
    {
        Setting::set(Setting::DISPLAY_DIVERSITY_HIGH_MIN, '2.8');

        $this->assertSame(2.8, ReportContent::diversityHighMin());
        // 2.7 was "High" at the 2.5 default; with the boundary at 2.8 it is now "Medium".
        $this->assertSame('Medium', ReportContent::diversityBand(2.7)['label']);
        $this->assertSame('High', ReportContent::diversityBand(2.81)['label']);
        // The printed legend follows the setting.
        $this->assertSame(['< 1.9', '1.9 - 2.8', '> 2.8'], array_column(ReportContent::diversityLegend(), 'range'));
    }

    public function test_setting_the_richness_boundary_moves_the_band_and_legend(): void
    {
        Setting::set(Setting::DISPLAY_RICHNESS_HEALTHY_MIN, '800');

        $this->assertSame(800.0, ReportContent::richnessHealthyMin());
        // 700 was "Healthy" at the 650 default; with the boundary at 800 it is now "Moderate".
        $this->assertSame('Moderate', ReportContent::richnessBand(700)['label']);
        $this->assertSame('Healthy', ReportContent::richnessBand(801)['label']);
        $this->assertSame(['< 400', '400 - 800', '> 800'], array_column(ReportContent::richnessLegend(), 'range'));
    }

    public function test_setting_the_target_tolerance_widens_the_on_target_window(): void
    {
        // Behaviour & Mood is a point-target insight (Firmicutes target 25).
        // At the 0.25 default, 25.5 is OUT of target; widen the window to 1.0 and it's IN.
        $this->assertNotSame('Target', HealthInsightRules::computeInsight('score_behaviour_mood', 25.5)['label']);

        Setting::set(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE, '1.0');

        $this->assertSame(1.0, HealthInsightRules::targetTolerance());
        $this->assertSame('Target', HealthInsightRules::computeInsight('score_behaviour_mood', 25.5)['label']);
    }

    // ── Resolvers are DEFENSIVE: bad stored values fall back, never invert ───

    public function test_blank_and_nonnumeric_values_fall_back_to_the_constant(): void
    {
        foreach (['', '   ', 'abc'] as $bad) {
            Setting::set(Setting::DISPLAY_DIVERSITY_HIGH_MIN, $bad);
            Setting::set(Setting::DISPLAY_RICHNESS_HEALTHY_MIN, $bad);
            Setting::set(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE, $bad);

            $this->assertSame(ReportContent::DIVERSITY_HIGH_MIN, ReportContent::diversityHighMin(), "diversity for [{$bad}]");
            $this->assertSame((float) ReportContent::RICHNESS_HEALTHY_MIN, ReportContent::richnessHealthyMin(), "richness for [{$bad}]");
            $this->assertSame(HealthInsightRules::TARGET_TOLERANCE, HealthInsightRules::targetTolerance(), "tolerance for [{$bad}]");
        }
    }

    public function test_out_of_range_or_inverted_values_fall_back_to_the_constant(): void
    {
        // Below/at the fixed Low cutoff (would invert the bands) → constant.
        Setting::set(Setting::DISPLAY_DIVERSITY_HIGH_MIN, '1.5');
        $this->assertSame(ReportContent::DIVERSITY_HIGH_MIN, ReportContent::diversityHighMin());
        // Above the sane ceiling → constant.
        Setting::set(Setting::DISPLAY_DIVERSITY_HIGH_MIN, '9');
        $this->assertSame(ReportContent::DIVERSITY_HIGH_MIN, ReportContent::diversityHighMin());

        Setting::set(Setting::DISPLAY_RICHNESS_HEALTHY_MIN, '300');   // ≤ 400 Low cutoff
        $this->assertSame((float) ReportContent::RICHNESS_HEALTHY_MIN, ReportContent::richnessHealthyMin());
        Setting::set(Setting::DISPLAY_RICHNESS_HEALTHY_MIN, '9000');  // > 5000 ceiling
        $this->assertSame((float) ReportContent::RICHNESS_HEALTHY_MIN, ReportContent::richnessHealthyMin());

        Setting::set(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE, '5');  // > 2 ceiling
        $this->assertSame(HealthInsightRules::TARGET_TOLERANCE, HealthInsightRules::targetTolerance());
        Setting::set(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE, '-1'); // < 0 floor
        $this->assertSame(HealthInsightRules::TARGET_TOLERANCE, HealthInsightRules::targetTolerance());
    }

    // ── classify() is UNTOUCHED by any of these display settings ─────────────

    public function test_classify_is_unaffected_by_the_display_settings(): void
    {
        // Edit all three (including values that shift the DISPLAY bands).
        Setting::set(Setting::DISPLAY_DIVERSITY_HIGH_MIN, '2.8');
        Setting::set(Setting::DISPLAY_RICHNESS_HEALTHY_MIN, '800');
        Setting::set(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE, '1.0');

        // Same verdicts as ReportContentBandsTest — the classification cannot move.
        $this->assertSame('Stable', ReportContent::classify(3.0, 700, 0.3));
        $this->assertSame('Imbalanced', ReportContent::classify(3.0, 700, 0.6));
        $this->assertSame('Imbalanced & Depleted', ReportContent::classify(1.5, 700, 0.3));
        $this->assertSame('Imbalanced & Depleted', ReportContent::classify(2.0, 300, 0.3));
        $this->assertSame('Imbalanced', ReportContent::classify(2.0, 700, 0.3));

        // A richness of 700 now prints "Moderate" (boundary 800) yet still classifies
        // Stable — proving the display band and the verdict are decoupled.
        $this->assertSame('Moderate', ReportContent::richnessBand(700)['label']);
        $this->assertSame('Stable', ReportContent::classify(3.0, 700, 0.3));
    }

    // ── Settings page: gate, fields, save, validation ────────────────────────

    public function test_page_is_super_admin_only(): void
    {
        $this->actingAs(new User(['name' => 'A', 'email' => 'a@cp.agency', 'role' => 'super_admin']));
        $this->assertTrue(Settings::canAccess());

        $this->actingAs(new User(['name' => 'B', 'email' => 'b@cp.agency', 'role' => 'admin']));
        $this->assertFalse(Settings::canAccess());
    }

    public function test_the_three_fields_exist_and_prefill_their_defaults(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('Report Display Thresholds')
            ->assertFormFieldExists(Setting::DISPLAY_DIVERSITY_HIGH_MIN, 'form')
            ->assertFormFieldExists(Setting::DISPLAY_RICHNESS_HEALTHY_MIN, 'form')
            ->assertFormFieldExists(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE, 'form')
            ->assertFormSet([
                Setting::DISPLAY_DIVERSITY_HIGH_MIN => ReportContent::num(ReportContent::DIVERSITY_HIGH_MIN),
                Setting::DISPLAY_RICHNESS_HEALTHY_MIN => ReportContent::num(ReportContent::RICHNESS_HEALTHY_MIN),
                Setting::HEALTH_INSIGHT_TARGET_TOLERANCE => ReportContent::num(HealthInsightRules::TARGET_TOLERANCE),
            ]);
    }

    public function test_saving_valid_values_persists_them_and_the_resolvers_read_them(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(Settings::class)
            ->fillForm([
                Setting::DISPLAY_DIVERSITY_HIGH_MIN => '2.8',
                Setting::DISPLAY_RICHNESS_HEALTHY_MIN => '800',
                Setting::HEALTH_INSIGHT_TARGET_TOLERANCE => '0.5',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2.8', Setting::get(Setting::DISPLAY_DIVERSITY_HIGH_MIN));
        $this->assertSame('800', Setting::get(Setting::DISPLAY_RICHNESS_HEALTHY_MIN));
        $this->assertSame('0.5', Setting::get(Setting::HEALTH_INSIGHT_TARGET_TOLERANCE));

        $this->assertSame(2.8, ReportContent::diversityHighMin());
        $this->assertSame(800.0, ReportContent::richnessHealthyMin());
        $this->assertSame(0.5, HealthInsightRules::targetTolerance());
    }

    public function test_validation_rejects_inverted_diversity_boundary(): void
    {
        $this->actAsSuperAdmin();

        // 1.5 is below the fixed Low cutoff (1.9) — would invert the bands.
        Livewire::test(Settings::class)
            ->fillForm([Setting::DISPLAY_DIVERSITY_HIGH_MIN => '1.5'])
            ->call('save')
            ->assertHasFormErrors([Setting::DISPLAY_DIVERSITY_HIGH_MIN]);

        // Nothing was persisted.
        $this->assertNull(Setting::get(Setting::DISPLAY_DIVERSITY_HIGH_MIN));
    }

    public function test_validation_rejects_inverted_richness_boundary(): void
    {
        $this->actAsSuperAdmin();

        // 300 is below the fixed Low cutoff (400).
        Livewire::test(Settings::class)
            ->fillForm([Setting::DISPLAY_RICHNESS_HEALTHY_MIN => '300'])
            ->call('save')
            ->assertHasFormErrors([Setting::DISPLAY_RICHNESS_HEALTHY_MIN]);
    }

    public function test_validation_rejects_out_of_range_and_nonnumeric_values(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(Settings::class)
            ->fillForm([
                Setting::DISPLAY_DIVERSITY_HIGH_MIN => '9',      // > 5 ceiling
                Setting::DISPLAY_RICHNESS_HEALTHY_MIN => '9000', // > 5000 ceiling
                Setting::HEALTH_INSIGHT_TARGET_TOLERANCE => '3', // > 2 ceiling
            ])
            ->call('save')
            ->assertHasFormErrors([
                Setting::DISPLAY_DIVERSITY_HIGH_MIN,
                Setting::DISPLAY_RICHNESS_HEALTHY_MIN,
                Setting::HEALTH_INSIGHT_TARGET_TOLERANCE,
            ]);

        Livewire::test(Settings::class)
            ->fillForm([Setting::HEALTH_INSIGHT_TARGET_TOLERANCE => 'abc'])
            ->call('save')
            ->assertHasFormErrors([Setting::HEALTH_INSIGHT_TARGET_TOLERANCE]);
    }
}
