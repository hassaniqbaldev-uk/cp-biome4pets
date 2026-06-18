<?php

namespace App\Providers;

use App\Support\SmtpConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Point the mailer at the admin-editable SMTP settings when configured.
        // Resilient: a missing settings table (pre-migration) leaves the default
        // mailer in place, so the app never errors trying to read config.
        try {
            SmtpConfig::apply();
        } catch (\Throwable) {
            // Keep the .env / default mailer.
        }
    }
}
