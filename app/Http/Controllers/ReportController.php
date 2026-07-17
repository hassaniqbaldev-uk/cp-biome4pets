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

        // Publish-gate WITH an admin-preview exception:
        //   - A published report is public (served to anyone), exactly as before.
        //   - An UNPUBLISHED report is served ONLY to a logged-in admin, as a preview
        //     (with a banner + no PDF option) so staff can review before publishing.
        //   - Anyone else on an unpublished report (a customer, or anyone with just
        //     the link and no session) gets the branded "being finalised" holding page.
        //     THIS is the draft-privacy protection — an unauthenticated visitor must
        //     NEVER see unpublished content. The pivot is real session auth + role
        //     (viewerIsAdmin()), which cannot be spoofed with a token alone.
        // (A trashed report still 404s via firstOrFail above.)
        if (! $report->isPublished() && ! $this->viewerIsAdmin()) {
            return $this->finalisingResponse();
        }

        // True only in the admin-preview case (unpublished, viewed by a logged-in
        // admin). Drives the preview banner and hides the not-yet-available PDF
        // option in the view. For a published report this is always false.
        $adminPreview = ! $report->isPublished();

        return view('report.show', compact('report', 'adminPreview'));
    }

    /**
     * Is the current viewer a logged-in staff member (admin or super admin)? Uses
     * real session authentication plus the role check — NOT anything a public
     * visitor can influence — so it is safe as the admin-preview pivot on the public
     * publish-gate. Anonymous requests (no session) are never admins.
     */
    private function viewerIsAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin();
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
     * The subscribe interstitial: explains the auto-adjusting plan before the CTA
     * hands off to the plan's Loop checkout URL. Product details come from the
     * REPORT's OWN instantiated steps (report_step_products) — the same swapped
     * source the report's product card + subscribe box use — so a sensitive-pet
     * variant shows its swapped product here too, not the base plan's. If the live
     * plan is missing/disabled or has no checkout URL, degrade gracefully back to
     * the report.
     */
    public function subscribe(string $token)
    {
        $report = Report::where('public_token', $token)
            ->with([
                'client', 'pet.client',
                // The report's instantiated (variant-swapped) products for the
                // interstitial, plus the live plan for its name/pricing + the
                // fallback path below.
                'steps.products.catalogProduct',
                'plan.steps.products.catalogProduct',
            ])
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

        // Product steps in order (skip prose/dietary steps for the product
        // progression). The first is the "first delivery"; the rest are upcoming.
        //
        // Source from the REPORT's OWN instantiated steps so a variant report shows
        // its SWAPPED product (e.g. "PetBiome AMR (Rosemary Free)"), consistent with
        // the report's product card + subscribe box. Older reports that were never
        // instantiated (no report_steps) fall back to the live plan, unchanged.
        $productSteps = $report->steps
            ->where('type', 'product')
            ->values();

        if ($productSteps->isEmpty()) {
            $productSteps = $plan->steps
                ->where('type', 'product')
                ->values();
        }

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

        $pdf = Pdf::loadView('report.pdf', compact('report'))
            ->setPaper('a4', 'portrait');

        return $pdf->download(self::pdfFilename($report));
    }

    /**
     * The downloaded PDF's filename: "{Owner Name} - {Pet Name}.pdf"
     * (e.g. "Jane Smith - Biscuit.pdf"), so a customer's download is identifiable
     * rather than a generic "report-…".
     *
     * The pet name comes from the report's FROZEN snapshot (petField), matching what
     * the report itself prints; the owner is the pet's client (falling back to the
     * directly-linked client).
     *
     * Degrades sensibly rather than ever producing an empty or broken name:
     *   both names      → "Jane Smith - Biscuit.pdf"
     *   pet missing     → "Jane Smith.pdf"
     *   owner missing   → "Biscuit.pdf"
     *   both missing    → "Report {sample_id}.pdf", or plain "Report.pdf" if there
     *                     is no sample id either.
     */
    public static function pdfFilename(Report $report): string
    {
        $parts = array_values(array_filter([
            self::filenamePart($report->petClient?->name),
            self::filenamePart($report->petField('name')),
        ], fn (string $p): bool => $p !== ''));

        if ($parts !== []) {
            return implode(' - ', $parts).'.pdf';
        }

        // Neither name usable — fall back to the sample id, then to a bare label.
        $sample = self::filenamePart($report->sample_id);

        return trim('Report '.$sample).'.pdf';
    }

    /**
     * Make one name safe to use inside a filename: transliterate accents to ASCII
     * (é → e) for maximum cross-platform/header compatibility, replace the characters
     * that are invalid on Windows/macOS/Linux (/ \ : * ? " < > |) plus control chars,
     * collapse whitespace, strip leading/trailing dots and spaces (which break or hide
     * files), and truncate long names. Apostrophes and hyphens are legal and kept, so
     * "O'Brien" survives intact. Returns '' when nothing usable remains.
     */
    private static function filenamePart(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = Str::ascii($value);                                        // accents → ASCII
        $value = preg_replace('#[/\\\\:*?"<>|]+#', ' ', $value) ?? $value;  // invalid → space
        $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? $value;  // control chars
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;              // collapse whitespace
        $value = trim($value, " .");                                        // no leading/trailing . or space

        // Keep each part well inside the ~255-char filesystem limit.
        return Str::limit($value, 60, '');
    }
}
