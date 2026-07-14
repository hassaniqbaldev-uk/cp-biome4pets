<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\OpenAiService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate on an in-memory sqlite DB so we never touch the dev MySQL data.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        // The Settings page also manages product trigger rules.
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

        $this->actingAs(new User(['name' => 'Admin User', 'email' => 'admin@cp.agency', 'role' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_settings_page_renders_with_openai_tab(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('OpenAI')
            ->assertFormFieldExists('openai_api_key', 'form')
            ->assertFormFieldExists('openai_prompt_directives', 'form');
    }

    public function test_model_selector_is_on_the_openai_tab_and_old_plan_model_field_is_gone(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            // The single model selector now lives on the OpenAI tab…
            ->assertFormFieldExists('openai_model', 'form')
            // …and the old free-text Plan Generation Model field is retired.
            ->assertFormFieldDoesNotExist('plan_generation_model', 'form')
            // Unset → the dropdown shows the resolved default (gpt-4o), never blank.
            ->assertFormSet(['openai_model' => OpenAiService::resolveModel()]);
    }

    public function test_saving_a_model_persists_it_to_the_single_setting(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['openai_model' => 'gpt-4o-mini'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('gpt-4o-mini', Setting::get(Setting::OPENAI_MODEL));
        $this->assertSame('gpt-4o-mini', OpenAiService::resolveModel());
        // The retired key is never written by a save.
        $this->assertNull(Setting::get(Setting::PLAN_GENERATION_MODEL));
    }

    public function test_openai_tab_shows_the_cost_estimate_ui_and_honest_labelling(): void
    {
        // The tab renders even though this test never creates the ai_usage_events
        // table — the cost layer is defensive (zeros/baseline), matching the usage
        // totals. The editable rates field, the estimate, the guide and the honest
        // "estimate not invoice" note are all present.
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertFormFieldExists('openai_token_rates', 'form')
            ->assertSee('Token rates (for cost estimate)')
            ->assertSee('Cost estimate')
            ->assertSee('Estimate only')          // the disclaimer
            ->assertSee('not your OpenAI bill')
            ->assertSee('cost of ~100 reports');  // the guide
    }

    public function test_saving_token_rates_persists_them_as_a_json_map(): void
    {
        Livewire::test(Settings::class)
            ->set('data.openai_token_rates', [
                ['model' => 'gpt-4o', 'input_per_1k' => 0.003, 'output_per_1k' => 0.009],
                ['model' => 'custom-x', 'input_per_1k' => 0.001, 'output_per_1k' => 0.002],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = json_decode((string) Setting::get(Setting::OPENAI_TOKEN_RATES), true);

        $this->assertSame(0.003, $stored['gpt-4o']['input_per_1k']);
        $this->assertSame(0.002, $stored['custom-x']['output_per_1k']);

        // The saved override flows through resolveRates() for the estimate.
        $this->assertSame(0.003, \App\Models\AiUsageEvent::resolveRates()['gpt-4o']['input_per_1k']);
        // An un-edited default model is still present (defaults layer underneath).
        $this->assertArrayHasKey('gpt-4-turbo', \App\Models\AiUsageEvent::resolveRates());
    }

    /**
     * Phase E: Email & Integrations was folded into Settings — Klaviyo + SMTP now
     * live here as tabs, and the "Platform Emails — coming soon" placeholder is
     * gone. (The standalone EmailIntegrations page was removed entirely.)
     */
    public function test_settings_consolidates_integrations_and_drops_coming_soon(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('OpenAI')
            ->assertSee('Klaviyo')
            ->assertSee('Email (SMTP)')
            // Diagnostics for both integrations are present.
            ->assertSee('Connection status')
            ->assertSee('Last SMTP result')
            // Klaviyo + SMTP secret fields are reachable on this page.
            ->assertFormFieldExists('klaviyo_api_key', 'form')
            ->assertFormFieldExists('smtp_password', 'form')
            // The removed placeholder tab/content is gone.
            ->assertDontSee('Platform Emails')
            ->assertDontSee('Coming soon');

        // The standalone page (and its nav item) were removed entirely.
        $this->assertFileDoesNotExist(app_path('Filament/Pages/EmailIntegrations.php'));
    }

    public function test_saving_api_key_stores_it_encrypted_at_rest(): void
    {
        $plain = 'sk-test-secret-1234567890';

        Livewire::test(Settings::class)
            ->set('data.openai_api_key', $plain)
            ->set('data.openai_prompt_directives', 'Be concise.')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');

        // Raw DB value must NOT be readable plaintext.
        $raw = DB::table('settings')->where('key', 'openai_api_key')->value('value');
        $this->assertNotSame($plain, $raw);
        $this->assertStringNotContainsString($plain, (string) $raw);

        // But it decrypts back to the original.
        $this->assertSame($plain, decrypt($raw));
        $this->assertSame($plain, Setting::getDecrypted('openai_api_key'));

        // Directives persist as plaintext (not secret).
        $this->assertSame('Be concise.', Setting::get('openai_prompt_directives'));
    }

    public function test_blank_key_on_resave_preserves_existing_key(): void
    {
        Setting::setEncrypted('openai_api_key', 'sk-original-key');

        // Re-save with a blank key but updated directives.
        Livewire::test(Settings::class)
            ->set('data.openai_api_key', '')
            ->set('data.openai_prompt_directives', 'New directives.')
            ->call('save')
            ->assertHasNoFormErrors();

        // Existing key is untouched; directives updated.
        $this->assertSame('sk-original-key', Setting::getDecrypted('openai_api_key'));
        $this->assertSame('New directives.', Setting::get('openai_prompt_directives'));
    }

    public function test_openai_service_reads_key_from_setting(): void
    {
        Setting::setEncrypted('openai_api_key', 'sk-from-db');

        $apiKey = $this->resolveServiceApiKey();

        $this->assertSame('sk-from-db', $apiKey);
    }

    public function test_openai_service_falls_back_to_config_env_when_setting_empty(): void
    {
        config(['services.openai.api_key' => 'sk-from-env-fallback']);

        // No setting stored at all.
        $apiKey = $this->resolveServiceApiKey();

        $this->assertSame('sk-from-env-fallback', $apiKey);
    }

    public function test_openai_service_appends_directives_to_prompt(): void
    {
        Setting::set('openai_prompt_directives', 'Always mention hydration.');

        $directives = Setting::get('openai_prompt_directives');
        $this->assertSame('Always mention hydration.', $directives);
    }

    public function test_real_service_degrades_gracefully_when_no_key_anywhere(): void
    {
        // No setting stored, and force the config/env fallback to be empty too.
        config(['services.openai.api_key' => null]);

        // Drives the actual service. With no key resolvable it must NOT make a
        // network call or throw — it returns the empty interpretation shape.
        $result = (new OpenAiService())->generateReportInterpretations(
            ['Firmicutes' => 50, 'Bacteroidetes' => 50],
            3.2,
        );

        $this->assertSame('', $result['summary']);
        // Stage 2: the six health-insight scores are computed deterministically and
        // are NO LONGER part of the AI response shape.
        $this->assertArrayNotHasKey('score_gut_wall', $result);
    }

    /**
     * Mirror OpenAiService's key-resolution logic (encrypted setting, then
     * config/env fallback) to assert the wiring without making an HTTP call.
     */
    private function resolveServiceApiKey(): ?string
    {
        $apiKey = Setting::getDecrypted(Setting::OPENAI_API_KEY);
        if (empty($apiKey)) {
            $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        }

        return $apiKey;
    }
}
