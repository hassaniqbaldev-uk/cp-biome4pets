<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function show(string $slug)
    {
        $report = Report::where('slug', $slug)
            ->with(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct'])
            ->firstOrFail();

        return view('report.show', compact('report'));
    }

    public function downloadPdf(string $slug)
    {
        // Eager-load the SAME relations as show() so the PDF can render every
        // section the web report does (notably the phased plan + its products).
        $report = Report::where('slug', $slug)
            ->with(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct'])
            ->firstOrFail();

        $filename = 'report-' . Str::slug($report->pet?->name) . '-' . $report->sample_id . '.pdf';

        $pdf = Pdf::loadView('report.pdf', compact('report'))
            ->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }
}
