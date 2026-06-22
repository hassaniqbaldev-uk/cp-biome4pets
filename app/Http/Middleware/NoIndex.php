<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tell every crawler, on every response, that nothing here may be indexed,
 * followed or archived. This is the authoritative control (robots.txt is only
 * advisory and a <meta> tag can't cover non-HTML responses like the PDF
 * download). Applied globally, so it also covers the admin panel and login.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
