<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Drives Laravel's mail transport from the admin-editable SMTP settings (Amazon
 * SES SMTP, 587 + STARTTLS). Applied on boot (see AppServiceProvider) so any
 * Mail::send / password-reset notification goes through the configured mailer.
 *
 * When SMTP is disabled or incomplete, apply() is a no-op and the app keeps the
 * .env / default (log) mailer — sending never errors for want of config.
 *
 * The password is read via Setting::getDecrypted (encrypted at rest, same as the
 * Klaviyo key); everything else is plaintext with a *_DEFAULT fallback.
 */
class SmtpConfig
{
    public static function isEnabled(): bool
    {
        return filter_var(Setting::get(Setting::SMTP_ENABLED), FILTER_VALIDATE_BOOLEAN);
    }

    /** The effective SMTP settings with defaults applied (password decrypted). */
    public static function resolved(): array
    {
        return [
            'host' => Setting::get(Setting::SMTP_HOST) ?: Setting::SMTP_HOST_DEFAULT,
            'port' => (int) (Setting::get(Setting::SMTP_PORT) ?: Setting::SMTP_PORT_DEFAULT),
            'encryption' => Setting::get(Setting::SMTP_ENCRYPTION) ?: Setting::SMTP_ENCRYPTION_DEFAULT,
            'username' => (string) Setting::get(Setting::SMTP_USERNAME),
            'password' => (string) Setting::getDecrypted(Setting::SMTP_PASSWORD, ''),
            'from_address' => Setting::get(Setting::SMTP_FROM_ADDRESS) ?: Setting::SMTP_FROM_ADDRESS_DEFAULT,
            'from_name' => Setting::get(Setting::SMTP_FROM_NAME) ?: Setting::SMTP_FROM_NAME_DEFAULT,
        ];
    }

    /** Enabled AND has the minimum needed to actually send. */
    public static function isConfigured(): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        $c = self::resolved();

        return filled($c['host'])
            && filled($c['username'])
            && filled($c['password'])
            && filled($c['from_address']);
    }

    /**
     * Point Laravel's default mailer at the stored SMTP settings. Returns true
     * when applied, false when SMTP is off/incomplete (caller can ignore). Port
     * 587 + encryption 'tls' makes the Symfony SMTP transport use STARTTLS.
     */
    public static function apply(): bool
    {
        if (! self::isConfigured()) {
            return false;
        }

        $c = self::resolved();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            // Leave scheme unset so the transport derives smtp+STARTTLS from the
            // tls encryption on 587 (smtps/implicit-TLS is only forced on 465).
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.host' => $c['host'],
            'mail.mailers.smtp.port' => $c['port'],
            'mail.mailers.smtp.encryption' => $c['encryption'] ?: null,
            'mail.mailers.smtp.username' => $c['username'],
            'mail.mailers.smtp.password' => $c['password'],
            'mail.from.address' => $c['from_address'],
            'mail.from.name' => $c['from_name'],
        ]);

        return true;
    }
}
