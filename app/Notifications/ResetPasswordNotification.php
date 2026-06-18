<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password-reset email. Extends Laravel's standard notification so the reset
 * URL still honours Filament's createUrlUsing callback (it points at the admin
 * panel's reset page) and the standard password broker / token flow is used —
 * we only customise the copy: plain, professional, British English, no em dashes.
 *
 * The from-address/name come from the applied mail config (the saved SMTP
 * settings) via MailMessage's default sender.
 */
class ResetPasswordNotification extends BaseResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello')
            ->line('We received a request to reset the password for your Biome4Pets portal account.')
            ->action('Reset password', $url)
            ->line('This link will expire in '.$minutes.' minutes.')
            ->line('If you did not request a password reset, no action is needed and your password will stay the same.')
            ->salutation('Thanks, the Biome4Pets team');
    }
}
