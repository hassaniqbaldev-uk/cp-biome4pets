<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\SmtpConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SMTP step: the mail transport is built from the admin-editable settings, the
 * password is stored encrypted (not plaintext), and disabled/incomplete SMTP
 * falls back to the default mailer without error.
 */
class SmtpMailConfigTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function saveValidSmtp(): void
    {
        Setting::set(Setting::SMTP_ENABLED, '1');
        Setting::set(Setting::SMTP_HOST, 'email-smtp.eu-west-2.amazonaws.com');
        Setting::set(Setting::SMTP_PORT, '587');
        Setting::set(Setting::SMTP_ENCRYPTION, 'tls');
        Setting::set(Setting::SMTP_USERNAME, 'AKIAEXAMPLE');
        Setting::setEncrypted(Setting::SMTP_PASSWORD, 'super-secret-pw');
        Setting::set(Setting::SMTP_FROM_ADDRESS, 'portal@biome4pets.com');
        Setting::set(Setting::SMTP_FROM_NAME, 'Biome4Pets');
    }

    public function test_apply_builds_mail_config_from_settings(): void
    {
        $this->saveValidSmtp();

        $this->assertTrue(SmtpConfig::isConfigured());
        $this->assertTrue(SmtpConfig::apply());

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('email-smtp.eu-west-2.amazonaws.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('tls', config('mail.mailers.smtp.encryption'));
        $this->assertSame('AKIAEXAMPLE', config('mail.mailers.smtp.username'));
        $this->assertSame('super-secret-pw', config('mail.mailers.smtp.password'));
        $this->assertSame('portal@biome4pets.com', config('mail.from.address'));
        $this->assertSame('Biome4Pets', config('mail.from.name'));
    }

    public function test_defaults_fill_in_when_settings_blank(): void
    {
        // Enabled with only the required credentials; host/port/from use defaults.
        Setting::set(Setting::SMTP_ENABLED, '1');
        Setting::set(Setting::SMTP_USERNAME, 'AKIAEXAMPLE');
        Setting::setEncrypted(Setting::SMTP_PASSWORD, 'pw');

        $resolved = SmtpConfig::resolved();
        $this->assertSame(Setting::SMTP_HOST_DEFAULT, $resolved['host']);
        $this->assertSame(587, $resolved['port']);
        $this->assertSame('tls', $resolved['encryption']);
        $this->assertSame(Setting::SMTP_FROM_ADDRESS_DEFAULT, $resolved['from_address']);
        $this->assertSame(Setting::SMTP_FROM_NAME_DEFAULT, $resolved['from_name']);
    }

    public function test_password_is_stored_encrypted_not_plaintext(): void
    {
        Setting::setEncrypted(Setting::SMTP_PASSWORD, 'plain-text-pw');

        $raw = DB::table('settings')->where('key', Setting::SMTP_PASSWORD)->value('value');

        $this->assertNotSame('plain-text-pw', $raw);
        $this->assertStringNotContainsString('plain-text-pw', (string) $raw);
        $this->assertSame('plain-text-pw', decrypt($raw));
        $this->assertSame('plain-text-pw', Setting::getDecrypted(Setting::SMTP_PASSWORD));
    }

    public function test_disabled_smtp_falls_back_cleanly(): void
    {
        $default = config('mail.default');

        // Fully configured but the master switch is OFF.
        $this->saveValidSmtp();
        Setting::set(Setting::SMTP_ENABLED, '0');

        $this->assertFalse(SmtpConfig::isConfigured());
        $this->assertFalse(SmtpConfig::apply());
        $this->assertSame($default, config('mail.default'));
    }

    public function test_enabled_but_incomplete_smtp_falls_back_cleanly(): void
    {
        $default = config('mail.default');

        // Enabled, but no password saved → not configured.
        Setting::set(Setting::SMTP_ENABLED, '1');
        Setting::set(Setting::SMTP_USERNAME, 'AKIAEXAMPLE');
        Setting::set(Setting::SMTP_FROM_ADDRESS, 'portal@biome4pets.com');

        $this->assertFalse(SmtpConfig::isConfigured());
        $this->assertFalse(SmtpConfig::apply());
        $this->assertSame($default, config('mail.default'));
    }
}
