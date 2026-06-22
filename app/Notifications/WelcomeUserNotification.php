<?php

namespace App\Notifications;

use App\Support\Utm;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome / account-created email, sent when a Super Admin creates a user via
 * UserResource. The CTA is a single-use "set your password" link (built from the
 * password broker so the new user chooses their own password rather than relying
 * on the admin-typed one). Renders the shared BRANDED template (emails.welcome →
 * emails.layout) so it matches the password-reset email.
 *
 * The set-password URL is built by the caller (CreateUser) via
 * Filament::getResetPasswordUrl(); we only tag it and render it.
 */
class WelcomeUserNotification extends Notification
{
    public function __construct(private string $setPasswordUrl)
    {
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Tag the email CTA for analytics attribution (utm_medium=email).
        $url = Utm::email($this->setPasswordUrl, 'welcome', 'welcome_cta');

        return (new MailMessage)
            ->subject('Welcome to the Biome4Pets portal')
            ->view('emails.welcome', [
                'url' => $url,
                'name' => $notifiable->name ?? null,
            ]);
    }
}
