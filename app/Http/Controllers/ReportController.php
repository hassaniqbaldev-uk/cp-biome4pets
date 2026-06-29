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

        // Publish-gate: a draft / unpublished report (incl. one a staff member has
        // unpublished to edit) must NEVER serve its content to the public — show the
        // branded "being finalised" holding page at the SAME url instead. Re-publishing
        // serves the report again. (A trashed report still 404s via firstOrFail above.)
        if (! $report->isPublished()) {
            return $this->finalisingResponse();
        }

        return view('report.show', compact('report'));
    }

    /**
     * The branded "this report is being finalised" holding page, served at a report's
     * public URL while it is draft / unpublished. 200 (not a 404 or error): the link
     * is valid, the content just isn't ready. Carries NO report content so nothing
     * leaks during an edit window.
     */
    private function finalisingResponse(): \Illuminate\Http\Response
    {
        return response()->view('report.finalising');
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

        // Same publish-gate as show(): never serve a draft/unpublished report's
        // subscribe pitch to the public.
        if (! $report->isPublished()) {
            return $this->finalisingResponse();
        }

        $plan = $report->plan;

        // The checkout target: the url FROZEN on this report (variant-or-base) with
        // the live plan url as fallback (Stage 4). So the customer is sent to exactly
        // the link quoted on their report; base/old reports are unchanged.
        $checkoutUrl = $report->checkoutUrl();

        // Degrade gracefully back to the report when there's nothing to subscribe to
        // OR when staff have hidden the subscribe pitch on this report — so a stale
        // /subscribe link can never drive a hidden-subscribe customer into checkout.
        // (The interstitial still needs an enabled plan to render its details.)
        if ($report->hide_subscribe || ! $plan || ! $plan->enabled || blank($checkoutUrl)) {
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
            // The resolved (frozen-or-live) Loop checkout target for the CTA + redirect.
            'checkoutUrl' => $checkoutUrl,
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

        // Same publish-gate as show(): never hand out a draft/unpublished report's PDF.
        if (! $report->isPublished()) {
            return $this->finalisingResponse();
        }

        $filename = 'report-' . Str::slug($report->pet?->name) . '-' . $report->sample_id . '.pdf';

        $pdf = Pdf::loadView('report.pdf', compact('report'))
            ->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }
}
