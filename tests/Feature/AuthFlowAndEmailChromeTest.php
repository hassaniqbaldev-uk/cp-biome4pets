<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\ResetPassword;
use App\Mail\TestSmtpEmail;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #1 friendly "already signed in" warning on the set-password link, #3 the test
 * email rendered through the shared branded layout, #4 password requirements shown
 * upfront on the reset/set-password page.
 */
class AuthFlowAndEmailChromeTest extends TestCase
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

    // ── #1 ───────────────────────────────────────────────────────────────────
    public function test_authenticated_user_on_reset_link_sees_friendly_warning_not_403(): void
    {
        $this->actingAs(User::create([
            'name' => 'Someone', 'email' => 'someone@e.com',
            'password' => Hash::make('secret'), 'role' => User::ROLE_ADMIN,
        ]));

        $res = $this->get('/admin/password-reset/reset?token=abc&email=other%40e.com');

        $res->assertOk();                                     // not a 403/blocked redirect
        $res->assertSee("You're already signed in", false);   // friendly explanation
        $res->assertSee('log out first', false);
        $res->assertSee('Forgot password', false);            // pointer to a fresh link
        $res->assertSee(route('filament.admin.auth.logout'), false); // log out button target
    }

    public function test_guest_with_a_valid_utm_tagged_signed_link_reaches_the_reset_form(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        $user = User::create([
            'name' => 'Newbie', 'email' => 'newbie@e.com',
            'password' => \Illuminate\Support\Facades\Hash::make('x'), 'role' => User::ROLE_ADMIN,
        ]);
        $token = \Illuminate\Support\Facades\Password::broker(\Filament\Facades\Filament::getAuthPasswordBroker())->createToken($user);

        // The real welcome/reset link: a SIGNED route, then UTM-tagged (as the
        // notifications do). The appended utm_* must NOT break the signature.
        $signedUtmUrl = \App\Support\Utm::email(\Filament\Facades\Filament::getResetPasswordUrl($token, $user), 'welcome', 'welcome_cta');

        $this->assertStringContainsString('utm_medium=email', $signedUtmUrl);

        $res = $this->get($signedUtmUrl);
        $res->assertOk();                                       // signature valid (utm ignored) — NOT 403
        $res->assertDontSee("You're already signed in", false); // guest → middleware is a no-op
    }

    // ── #3 ───────────────────────────────────────────────────────────────────
    public function test_test_email_renders_through_the_shared_branded_layout(): void
    {
        $html = (new TestSmtpEmail())->render();

        $this->assertStringContainsString('biome4pets-logo.png', $html);   // coloured logo
        $this->assertStringContainsString('#4654A4', $html);               // brand accent/button
        $this->assertStringContainsString('info@biome4pets.com', $html);   // shared footer
        $this->assertStringContainsString('SMTP settings are working', $html); // body copy
    }

    // ── #4 ───────────────────────────────────────────────────────────────────
    public function test_reset_page_shows_password_requirements_upfront(): void
    {
        // Mount the custom reset/set-password page as a guest; the helperText is
        // visible before any submit.
        Livewire::test(ResetPassword::class, ['token' => 'abc', 'email' => 'g@e.com'])
            ->assertSee('at least 12 characters')
            ->assertSee('upper and lower case')
            ->assertSee('data breach');
    }
}
