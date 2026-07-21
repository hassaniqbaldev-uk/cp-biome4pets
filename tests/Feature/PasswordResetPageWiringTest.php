<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for the forgot-password bug: the email field was DISABLED and
 * unusable.
 *
 * Cause: passwordReset()'s FIRST positional arg is the REQUEST (forgot-password)
 * page, its second is the RESET (set-password) page. The panel passed the custom
 * ResetPassword — whose email field is intentionally disabled (the account is fixed
 * by the token) — positionally, so it landed on the REQUEST route. Users then saw a
 * disabled email field on "forgot password" and couldn't request a link. Fixed by
 * passing it as the named `resetAction:` argument.
 */
class PasswordResetPageWiringTest extends TestCase
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
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_the_forgot_password_page_is_filaments_editable_request_page(): void
    {
        $panel = Filament::getPanel('admin');

        // The REQUEST (forgot-password) page must be Filament's default — its email
        // field is editable, so a user can type an address and request a link. It must
        // NOT be the custom set-password page (whose email field is disabled).
        $this->assertSame(
            RequestPasswordReset::class,
            $panel->getRequestPasswordResetRouteAction(),
        );
        $this->assertNotSame(
            \App\Filament\Pages\Auth\ResetPassword::class,
            $panel->getRequestPasswordResetRouteAction(),
        );

        // The RESET (set-password) page keeps the custom page (password helper text).
        $this->assertSame(
            \App\Filament\Pages\Auth\ResetPassword::class,
            $panel->getResetPasswordRouteAction(),
        );
    }

    public function test_the_forgot_password_page_renders_an_editable_email_field(): void
    {
        $html = $this->get('/admin/password-reset/request')
            ->assertOk()
            ->getContent();

        // The email field is present and bound. NB: the correct (default) request page
        // binds "data.email" via the form statePath — the buggy set-password page used
        // "email" directly, with type="text" and disabled. So the binding itself is a
        // fingerprint of the right page.
        $this->assertStringContainsString('wire:model="data.email"', $html);

        // Pull the actual email <input> and assert it carries no disabled/readonly
        // ATTRIBUTE (the Tailwind classes contain the word "disabled" for styling, so
        // match the attribute form specifically).
        $this->assertMatchesRegularExpression('/<input\b[^>]*wire:model="data\.email"[^>]*>/', $html);
        preg_match('/<input\b[^>]*wire:model="data\.email"[^>]*>/', $html, $m);
        $emailInput = $m[0];
        $this->assertStringContainsString('type="email"', $emailInput);
        $this->assertStringNotContainsString('disabled="disabled"', $emailInput, 'the forgot-password email field must be editable');
        $this->assertDoesNotMatchRegularExpression('/\breadonly\b/', $emailInput);

        // The forgot-password form asks ONLY for the email — no password fields (those
        // belong to the set-password page, which is where the disabled email lived).
        $this->assertStringNotContainsString('wire:model="data.password"', $html);
        $this->assertStringNotContainsString('wire:model="password"', $html);
    }
}
