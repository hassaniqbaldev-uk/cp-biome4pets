<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function show(string $token)
    {
        $report = Report::where('public_token', $token)
            ->with(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct'])
            ->firstOrFail();

        return view('report.show', compact('report'));
    }

    /**
     * The subscribe interstitial: explains the auto-adjusting plan (from the LIVE
     * plan data) before the CTA hands off to the plan's Loop checkout URL. If the
     * live plan is missing/disabled or has no checkout URL, degrade gracefully
     * back to the report.
     */
    public function subscribe(string $token)
    {
        $report = Report::where('public_token', $token)
            ->with(['client', 'pet.client', 'plan.steps.products.catalogProduct'])
            ->firstOrFail();

        $plan = $report->plan;

        if (! $plan || ! $plan->enabled || blank($plan->subscription_url)) {
            return redirect()->route('report.show', ['token' => $report->public_token]);
        }

        // Product steps in plan order (skip prose/dietary steps for the product
        // progression). The first is the "first delivery"; the rest are upcoming.
        $productSteps = $plan->steps
            ->where('type', 'product')
            ->values();

        $firstStep = $productSteps->first();

        return view('report.subscribe', [
            'report' => $report,
            'plan' => $plan,
            'petName' => $report->petField('name') ?: 'your dog',
            'firstStep' => $firstStep,
            'firstProduct' => $firstStep?->products->first(),
            'upcomingSteps' => $productSteps->slice(1)->values(),
            // Admin-editable review figures (Settings → Plans / Generation →
            // Reviews); each falls back to its default so the page is never blank.
            'reviewRating' => Setting::get(Setting::REVIEW_RATING) ?: Setting::REVIEW_RATING_DEFAULT,
            'reviewCount' => Setting::get(Setting::REVIEW_COUNT) ?: Setting::REVIEW_COUNT_DEFAULT,
        ]);
    }

    public function downloadPdf(string $token)
    {
        // Eager-load the SAME relations as show() so the PDF can render every
        // section the web report does (notably the phased plan + its products).
        $report = Report::where('public_token', $token)
            ->with(['client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct'])
            ->firstOrFail();

        $filename = 'report-' . Str::slug($report->pet?->name) . '-' . $report->sample_id . '.pdf';

        $pdf = Pdf::loadView('report.pdf', compact('report'))
            ->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }
}
