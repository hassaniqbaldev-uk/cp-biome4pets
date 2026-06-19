<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    /**
     * Review figures shown on the subscribe interstitial. PLACEHOLDERS — set
     * these to the real values here. (Swap for a Setting/config later if they
     * should be admin-editable.)
     */
    public const REVIEW_RATING = '4.9';      // e.g. "4.9"

    public const REVIEW_COUNT = '1,000+';    // e.g. "2,300+"

    public function show(string $slug)
    {
        $report = Report::where('slug', $slug)
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
    public function subscribe(string $slug)
    {
        $report = Report::where('slug', $slug)
            ->with(['client', 'pet.client', 'plan.steps.products.catalogProduct'])
            ->firstOrFail();

        $plan = $report->plan;

        if (! $plan || ! $plan->enabled || blank($plan->subscription_url)) {
            return redirect()->route('report.show', ['slug' => $report->slug]);
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
            'reviewRating' => self::REVIEW_RATING,
            'reviewCount' => self::REVIEW_COUNT,
        ]);
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
