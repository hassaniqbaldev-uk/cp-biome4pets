<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\WelcomeUserNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Account-lifecycle emails:
 *  - Creating a user via UserResource sends the branded welcome email with a
 *    "set your password" CTA (single-use link), tagged utm_medium=email.
 *  - Editing a user does NOT re-send it.
 *  - Both the welcome and reset emails render the shared branded layout (coloured
 *    logo, table button) and carry the right UTMs.
 */
class WelcomeEmailTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Boss', 'email' => 'boss@cp.agency',
            'password' => bcrypt('SuperSecret123!'), 'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    public function test_creating_a_user_sends_the_welcome_email(): void
    {
        Notification::fake();
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Admin',
                'email' => 'new.admin@example.com',
                'role' => User::ROLE_ADMIN,
                'password' => 'StrongPassw0rd!!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'new.admin@example.com')->firstOrFail();
        Notification::assertSentTo($user, WelcomeUserNotification::class);
    }

    public function test_editing_a_user_does_not_resend_the_welcome_email(): void
    {
        $this->actingAs($this->superAdmin());
        $existing = User::create([
            'name' => 'Existing', 'email' => 'existing@example.com',
            'password' => bcrypt('StrongPassw0rd!!'), 'role' => User::ROLE_ADMIN,
        ]);

        Notification::fake();

        Livewire::test(EditUser::class, ['record' => $existing->getRouteKey()])
            ->fillForm(['name' => 'Existing Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertNotSentTo($existing, WelcomeUserNotification::class);
    }

    public function test_welcome_email_renders_branded_with_set_password_cta_and_utm(): void
    {
        $user = new User(['name' => 'Alex', 'email' => 'alex@example.com']);

        $mail = (new WelcomeUserNotification('https://app.test/admin/password-reset/reset/tok?email=a%40b.com'))
            ->toMail($user);

        $this->assertSame('Welcome to the Biome4Pets portal', $mail->subject);
        $this->assertSame('emails.welcome', $mail->view);

        // CTA is tagged as an email welcome link, with the token URL preserved.
        $this->assertStringContainsString('utm_medium=email', $mail->viewData['url']);
        $this->assertStringContainsString('utm_campaign=welcome', $mail->viewData['url']);
        $this->assertStringContainsString('/reset/tok', $mail->viewData['url']);

        $html = view($mail->view, $mail->viewData)->render();
        $this->assertStringContainsString('biome4pets-logo.png', $html);   // COLOURED logo
        $this->assertStringNotContainsString('logo-white', $html);
        $this->assertStringContainsString('Set your password', $html);     // CTA label
        $this->assertStringContainsString('Welcome to Biome4Pets, Alex', $html);
    }

    public function test_reset_email_renders_same_branded_template_and_is_tagged(): void
    {
        // The reset URL is resolved by the broker's createUrlUsing callback (the
        // same hook Filament registers in production for the admin reset page).
        ResetPasswordNotification::createUrlUsing(
            fn ($notifiable, $token) => 'https://app.test/admin/password-reset/reset/'.$token.'?email=a%40b.com'
        );

        $mail = (new ResetPasswordNotification('tok123'))
            ->toMail(new User(['name' => 'Alex', 'email' => 'alex@example.com']));

        ResetPasswordNotification::createUrlUsing(null);

        $this->assertSame('emails.reset-password', $mail->view);
        $this->assertStringContainsString('utm_medium=email', $mail->viewData['url']);
        $this->assertStringContainsString('utm_campaign=password_reset', $mail->viewData['url']);

        $html = view($mail->view, $mail->viewData)->render();
        $this->assertStringContainsString('biome4pets-logo.png', $html);   // same branded layout
        $this->assertStringContainsString('Reset password', $html);
    }
}
