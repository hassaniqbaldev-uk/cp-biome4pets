<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * L2 — per-user throttle for admin actions that call paid/expensive external
 * APIs (OpenAI generation, SMTP test sends, Klaviyo events). A human clicking a
 * button can't legitimately exceed a few per minute, so these caps are invisible
 * in normal use but stop a compromised or careless admin from running up
 * unbounded third-party cost.
 *
 * Usage at the top of an action closure:
 *
 *     if (PaidActionLimiter::exceeded('generate-ai', 10)) {
 *         return;
 *     }
 *
 * When the cap is hit it sends a warning notification and returns true so the
 * caller bails out before doing the paid work.
 */
class PaidActionLimiter
{
    public static function exceeded(string $action, int $perMinute): bool
    {
        $key = 'paid-action:'.$action.':'.(auth()->id() ?? request()->ip());

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $seconds = RateLimiter::availableIn($key);

            Notification::make()
                ->title('Too many attempts')
                ->body("You've run this action too many times in a short period. Please wait {$seconds} seconds and try again.")
                ->warning()
                ->send();

            return true;
        }

        // Count this attempt; the window decays after 60 seconds.
        RateLimiter::hit($key);

        return false;
    }
}
