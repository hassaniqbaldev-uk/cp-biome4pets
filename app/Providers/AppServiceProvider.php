<?php

namespace App\Providers;

use App\Support\SmtpConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        $this->configurePasswordPolicy();
        $this->configureRateLimiters();

        // Filament's password-reset / set-password URL is a SIGNED route. We append
        // UTM params (Utm::email) to those email links for analytics, which would
        // otherwise invalidate the signature → a 403 when the user clicks the link.
        // Ignore utm_* during signature validation so the links work AND stay
        // tagged. Security is unaffected: the email+token are still signed and the
        // token is independently validated by the password broker.
        ValidateSignature::except([
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        ]);
    }

    /**
     * M3 — strong-password baseline for every admin who handles customer PII.
     * Applies anywhere Password::default() is used: the UserResource create/edit
     * form and Filament's built-in password-reset page.
     *
     * The HaveIBeenPwned (->uncompromised()) check makes a live API call, so it
     * runs in real environments but is skipped under `testing` to keep the suite
     * hermetic and offline. If HIBP is unreachable in production the rule fails
     * open (treats the password as acceptable) — by design, so an outage can't
     * lock admins out.
     */
    protected function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->mixedCase()->numbers();

            return $this->app->environment('testing') ? $rule : $rule->uncompromised();
        });
    }

    /**
     * L1 — per-IP throttles for the public report routes. The PDF route runs
     * DomPDF on every hit (CPU-heavy), so it gets a tighter cap than the HTML
     * views. Limits are generous for genuine use and only bite on hammering.
     *
     *   report      (show + subscribe HTML) : 60 requests / minute / IP
     *   report-pdf  (DomPDF render)         : 30 requests / minute / IP
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('report', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('report-pdf', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
