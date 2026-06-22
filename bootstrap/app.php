<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Authoritative "do not index/follow/archive" on EVERY response — public
        // reports, the PDF download, the admin panel and login included.
        $middleware->append(\App\Http\Middleware\NoIndex::class);
        // Baseline security headers everywhere (clickjacking/MIME/referrer/HSTS),
        // plus a CSP scoped to the public report pages.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
