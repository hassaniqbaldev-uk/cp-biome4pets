<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (clickjacking, MIME-sniffing,
 * referrer leakage, transport security), plus a Content-Security-Policy applied
 * ONLY to the public report HTML pages.
 *
 * The CSP is deliberately scoped to the report views: the Filament admin (Livewire
 * + Alpine) needs a much looser policy (e.g. 'unsafe-eval'), so a strict global
 * CSP would break it. X-Frame-Options/nosniff/Referrer-Policy/HSTS still apply
 * everywhere, including the admin panel and the PDF download.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');                       // clickjacking
        $headers->set('X-Content-Type-Options', 'nosniff');             // MIME sniffing
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS only in production (it would break local/staging over plain http).
        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP only on the public report HTML pages (not the admin panel, not the
        // PDF binary). frame-ancestors 'none' is a second clickjacking layer.
        if ($request->routeIs('report.show', 'report.subscribe')) {
            $headers->set('Content-Security-Policy', $this->reportCsp());
        }

        return $response;
    }

    /**
     * Conservative CSP for the public report pages. Allows exactly what those
     * pages legitimately load: self, inline styles/scripts (the report is built
     * with inline style="" + inline Chart.js init), Chart.js from jsDelivr, https
     * images (catalogue product images are admin-set, arbitrary https), and the
     * Feedbucket feedback widget (cdn.feedbucket.app + its API).
     *
     * NOTE: Feedbucket is a third-party screen-capture widget. It is NO LONGER
     * shown to public/customer report viewers — the report views only inject it
     * for an authenticated admin previewing the page (see the @auth guard in
     * show/subscribe.blade.php). The feedbucket CSP allowances are KEPT because the
     * widget can still legitimately load on these pages for that logged-in-staff
     * case; drop them only if you remove Feedbucket from the report views entirely.
     */
    protected function reportCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.feedbucket.app",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            "connect-src 'self' https://cdn.feedbucket.app https://*.feedbucket.app",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self' https:",
        ]);
    }
}
