<?php

namespace App\Notifications;

use App\Support\Utm;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password-reset email. Extends Laravel's standard notification so the reset URL
 * still comes from Filament (it points at the admin panel's reset page, set on
 * $this->url) and the standard password broker / token flow is used. Renders the
 * shared BRANDED HTML template (emails.reset-password → emails.layout) so it
 * matches the welcome email, rather than Laravel's default markdown.
 *
 * The from-address/name come from the applied mail config (the saved SMTP
 * settings) via MailMessage's default sender.
 */
class ResetPasswordNotification extends BaseResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        // Tag the email link once for analytics attribution. The reset route is
        // token-based (not signature-validated), so extra query params are safe;
        // the helper preserves the existing email/token query params.
        $url = Utm::email($url, 'password_reset', 'reset_button');

        return (new MailMessage)
            ->subject('Reset your password')
            ->view('emails.reset-password', [
                'url' => $url,
                'minutes' => $minutes,
            ]);
    }
}
