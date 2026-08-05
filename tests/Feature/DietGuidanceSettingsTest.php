<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 2 of the diet-guidance build: the AI PROMPT (Setting::DIET_GUIDANCE_PROMPT) and
 * the finding→food RULES table (Setting::DIET_GUIDANCE_RULES, a JSON Setting edited via
 * a Repeater) are admin-editable in Settings, Super-Admin only. Both follow the
 * blank/unset → coded-default contract (never empty). No report rendering or generation
 * is wired this step — storage + admin UI only.
 */
class DietGuidanceSettingsTest extends TestCase
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
        $this->actingAs(new User(['name' => 'S', 'email' => 's@cp.agency', 'role' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ── PROMPT: default / stored / blank ─────────────────────────────────────

    public function test_prompt_resolves_to_default_when_unset(): void
    {
        $this->assertSame(Setting::DIET_GUIDANCE_PROMPT_DEFAULT, Setting::dietGuidancePrompt());
        $this->assertStringContainsString("'Foods to Include'", Setting::dietGuidancePrompt());
        $this->assertStringContainsString('between 8 and 12 foods', Setting::dietGuidancePrompt());
    }

    public function test_prompt_uses_the_stored_value_when_set(): void
    {
        Setting::set(Setting::DIET_GUIDANCE_PROMPT, 'Custom diet prompt.');
        $this->assertSame('Custom diet prompt.', Setting::dietGuidancePrompt());
    }

    public function test_prompt_blank_or_whitespace_falls_back_to_default(): void
    {
        foreach (['', '   ', "  \n "] as $blank) {
            Setting::set(Setting::DIET_GUIDANCE_PROMPT, $blank);
            $this->assertSame(Setting::DIET_GUIDANCE_PROMPT_DEFAULT, Setting::dietGuidancePrompt());
        }
    }

    // ── RULES: default rows / stored rows / blank / malformed ───────────────

    public function test_rules_resolve_to_the_nine_default_rows_when_unset(): void
    {
        $rules = Setting::dietGuidanceRules();

        $this->assertCount(9, $rules);
        $this->assertSame(['finding' => 'High Proteobacteria', 'recommend' => 'White fish, bone broth, pumpkin', 'reason' => 'Reduce gut stress and support recovery'], $rules[0]);
        $this->assertSame(['finding' => 'Low carbohydrate fermenters', 'recommend' => 'Pumpkin, apple, squash', 'reason' => 'Increase fermentable carbohydrates'], $rules[8]);
        // Every row has the three text fields.
        foreach ($rules as $row) {
            $this->assertSame(['finding', 'recommend', 'reason'], array_keys($row));
        }
    }

    public function test_rules_use_stored_rows_when_saved(): void
    {
        Setting::set(Setting::DIET_GUIDANCE_RULES, json_encode([
            ['finding' => 'Custom finding', 'recommend' => 'Carrots', 'reason' => 'Because'],
            ['finding' => 'Second', 'recommend' => 'Kale', 'reason' => 'Fibre'],
        ]));

        $rules = Setting::dietGuidanceRules();
        $this->assertCount(2, $rules);
        $this->assertSame('Custom finding', $rules[0]['finding']);
        $this->assertSame('Kale', $rules[1]['recommend']);
    }

    public function test_rules_blank_malformed_or_all_empty_fall_back_to_default(): void
    {
        foreach (['', '   ', 'not json', '{}', '[]', json_encode([['finding' => '', 'recommend' => '', 'reason' => '']])] as $bad) {
            Setting::set(Setting::DIET_GUIDANCE_RULES, $bad);
            $this->assertCount(9, Setting::dietGuidanceRules(), "bad value [{$bad}] must fall back to the 9 defaults");
        }
    }

    public function test_rules_drop_fully_blank_rows_but_keep_partial_ones(): void
    {
        Setting::set(Setting::DIET_GUIDANCE_RULES, json_encode([
            ['finding' => 'Keep me', 'recommend' => '', 'reason' => ''],   // partial → kept
            ['finding' => '', 'recommend' => '', 'reason' => ''],          // blank → dropped
        ]));

        $rules = Setting::dietGuidanceRules();
        $this->assertCount(1, $rules);
        $this->assertSame('Keep me', $rules[0]['finding']);
    }

    // ── Settings page: Super-Admin gate + round-trip save ───────────────────

    public function test_settings_page_is_super_admin_only(): void
    {
        $this->actingAs(new User(['name' => 'A', 'email' => 'a@cp.agency', 'role' => 'admin']));
        $this->assertFalse(Settings::canAccess());

        $this->actingAs(new User(['name' => 'S', 'email' => 's@cp.agency', 'role' => 'super_admin']));
        $this->assertTrue(Settings::canAccess());
    }

    public function test_fields_exist_and_prefill_their_defaults(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('Diet Guidance (AI)')
            ->assertFormFieldExists(Setting::DIET_GUIDANCE_PROMPT, 'form')
            ->assertFormFieldExists('diet_guidance_rules', 'form')
            ->assertFormSet([Setting::DIET_GUIDANCE_PROMPT => Setting::DIET_GUIDANCE_PROMPT_DEFAULT]);
    }

    public function test_saving_prompt_and_rules_persists_and_resolves(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(Settings::class)
            ->fillForm([Setting::DIET_GUIDANCE_PROMPT => 'Edited prompt for diet boxes.'])
            ->set('data.diet_guidance_rules', [
                ['finding' => 'Edited finding', 'recommend' => 'Turkey', 'reason' => 'Gentle protein'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edited prompt for diet boxes.', Setting::dietGuidancePrompt());

        $rules = Setting::dietGuidanceRules();
        $this->assertCount(1, $rules);
        $this->assertSame(['finding' => 'Edited finding', 'recommend' => 'Turkey', 'reason' => 'Gentle protein'], $rules[0]);
    }

    public function test_saving_blank_prompt_restores_the_default(): void
    {
        $this->actAsSuperAdmin();

        Setting::set(Setting::DIET_GUIDANCE_PROMPT, 'temporary');
        $this->assertSame('temporary', Setting::dietGuidancePrompt());

        Livewire::test(Settings::class)
            ->fillForm([Setting::DIET_GUIDANCE_PROMPT => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Setting::DIET_GUIDANCE_PROMPT_DEFAULT, Setting::dietGuidancePrompt());
    }
}
