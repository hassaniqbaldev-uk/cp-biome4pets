<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/report/{slug}', [ReportController::class, 'show'])->name('report.show');
Route::get('/report/{slug}/pdf', [ReportController::class, 'downloadPdf'])->name('report.pdf');
