<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Mail\TestSmtpEmail;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Email & Integrations SMTP tab: saving encrypts the password and a blank
 * password leaves the stored one unchanged; the "Send test email" action sends
 * through the configured mailer.
 */
class EmailIntegrationsSmtpTest extends TestCase
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

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_saving_encrypts_the_password_and_blank_keeps_it(): void
    {
        Livewire::test(Settings::class)
            ->set('data.'.Setting::SMTP_ENABLED, true)
            ->set('data.'.Setting::SMTP_HOST, 'email-smtp.eu-west-2.amazonaws.com')
            ->set('data.'.Setting::SMTP_PORT, '587')
            ->set('data.'.Setting::SMTP_ENCRYPTION, 'tls')
            ->set('data.'.Setting::SMTP_USERNAME, 'AKIAEXAMPLE')
            ->set('data.'.Setting::SMTP_PASSWORD, 'first-pw')
            ->set('data.'.Setting::SMTP_FROM_ADDRESS, 'portal@biome4pets.com')
            ->set('data.'.Setting::SMTP_FROM_NAME, 'Biome4Pets')
            ->call('save')
            ->assertHasNoErrors();

        // Stored encrypted (raw DB value is not the plaintext), decrypts back.
        $raw = DB::table('settings')->where('key', Setting::SMTP_PASSWORD)->value('value');
        $this->assertNotSame('first-pw', $raw);
        $this->assertSame('first-pw', Setting::getDecrypted(Setting::SMTP_PASSWORD));
        $this->assertSame('1', Setting::get(Setting::SMTP_ENABLED));
        $this->assertSame('AKIAEXAMPLE', Setting::get(Setting::SMTP_USERNAME));

        // Re-save with a blank password but a changed username → password kept.
        Livewire::test(Settings::class)
            ->set('data.'.Setting::SMTP_USERNAME, 'AKIA-CHANGED')
            ->set('data.'.Setting::SMTP_PASSWORD, '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('first-pw', Setting::getDecrypted(Setting::SMTP_PASSWORD));
        $this->assertSame('AKIA-CHANGED', Setting::get(Setting::SMTP_USERNAME));
    }

    public function test_send_test_email_sends_through_the_configured_mailer(): void
    {
        // Saved, complete SMTP settings.
        Setting::set(Setting::SMTP_ENABLED, '1');
        Setting::set(Setting::SMTP_HOST, 'email-smtp.eu-west-2.amazonaws.com');
        Setting::set(Setting::SMTP_PORT, '587');
        Setting::set(Setting::SMTP_ENCRYPTION, 'tls');
        Setting::set(Setting::SMTP_USERNAME, 'AKIAEXAMPLE');
        Setting::setEncrypted(Setting::SMTP_PASSWORD, 'pw');
        Setting::set(Setting::SMTP_FROM_ADDRESS, 'portal@biome4pets.com');
        Setting::set(Setting::SMTP_FROM_NAME, 'Biome4Pets');

        Mail::fake();

        Livewire::test(Settings::class)
            ->call('runSendTestEmail', 'client@example.com');

        Mail::assertSent(TestSmtpEmail::class, fn (TestSmtpEmail $mail) => $mail->hasTo('client@example.com'));

        $result = json_decode((string) Setting::get(Setting::SMTP_LAST_RESULT), true);
        $this->assertTrue($result['ok']);
    }
}
