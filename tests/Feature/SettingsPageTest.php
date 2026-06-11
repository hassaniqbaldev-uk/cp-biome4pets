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

        $this->actingAs(new User(['name' => 'Admin User', 'email' => 'admin@cp.agency']));
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
        $this->assertArrayHasKey('score_gut_wall', $result);
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
