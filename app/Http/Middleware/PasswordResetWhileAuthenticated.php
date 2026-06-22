<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The set-password / password-reset link is a guest flow. If an ALREADY logged-in
 * user clicks it (e.g. a new user's welcome link opened in a browser still signed
 * in as someone else), Filament would block/redirect them with no explanation.
 * Instead, render a friendly warning page: explain they're logged in, offer a log
 * out, and point them at "Forgot password" in case the emailed link has expired.
 *
 * Runs in the panel's base middleware stack but only acts on the reset route when
 * authenticated — a no-op everywhere else. We do NOT auto-log-out; the user
 * chooses (a "Log out" button is on the rendered page).
 */
class PasswordResetWhileAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && $request->routeIs('filament.admin.auth.password-reset.reset')) {
            return response()->view('auth.password-reset-while-logged-in', [
                'logoutUrl' => route('filament.admin.auth.logout'),
                'forgotUrl' => route('filament.admin.auth.password-reset.request'),
                'loginUrl' => route('filament.admin.auth.login'),
            ]);
        }

        return $next($request);
    }
}
