<?php

use App\Http\Controllers\ReportController;
use App\Http\Controllers\TestCsvController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

// Public reports are keyed on the high-entropy public_token (NOT the guessable
// slug) so the URLs can't be enumerated. ReportController resolves by token.
// Per-IP throttles (defined in AppServiceProvider) blunt hammering; the PDF
// route renders DomPDF per hit so it gets the tighter 'report-pdf' limiter.
Route::get('/report/{token}', [ReportController::class, 'show'])
    ->middleware('throttle:report')->name('report.show');
Route::get('/report/{token}/pdf', [ReportController::class, 'downloadPdf'])
    ->middleware('throttle:report-pdf')->name('report.pdf');
// Subscribe interstitial — explains the auto-adjusting plan, then the CTA links
// to the live plan's Loop checkout URL.
Route::get('/report/{token}/subscribe', [ReportController::class, 'subscribe'])
    ->middleware('throttle:report')->name('report.subscribe');

// Authenticated download of a Test's private lab CSV (admins only; never public).
// Auth is enforced in the controller (any logged-in admin) — the route inherits
// the web group (session), so a logged-in Filament admin is recognised.
Route::get('/admin/tests/{test}/csv', [TestCsvController::class, 'download'])
    ->name('admin.tests.csv');
