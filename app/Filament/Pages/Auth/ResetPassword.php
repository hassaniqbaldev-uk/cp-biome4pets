<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Pages\Auth\PasswordReset\ResetPassword as BaseResetPassword;

/**
 * The shared reset / SET-password page (the welcome email's "set your password"
 * link and the "forgot password" link both land here). Customised only to show
 * the password requirements UPFRONT — a clear helperText under the field, before
 * any submit — so users aren't surprised by validation errors after the fact.
 *
 * Live per-keystroke met/unmet hints were considered but deliberately skipped:
 * they'd require custom JS on Filament's auth page (fragile) for little gain over
 * always-visible requirements. The helperText mirrors the Password::default()
 * policy in AppServiceProvider (min 12, mixed case, a number, not breached).
 */
class ResetPassword extends BaseResetPassword
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->helperText('Use at least 12 characters, with upper and lower case letters and at least one number. Avoid passwords that have appeared in a known data breach.');
    }
}
