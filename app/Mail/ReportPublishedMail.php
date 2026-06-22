<?php

namespace App\Mail;

use App\Models\Report;
use App\Support\Utm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "your report is ready" email for the "Send via App" (direct SMTP) path —
 * the in-house equivalent of the Klaviyo report_published email. Renders through
 * the SAME shared branded layout (emails/layout.blade.php) as welcome/reset/test,
 * so chrome is identical (coloured logo, #4654A4 accent/button, footer); only the
 * body differs. The CTA links to the report's PLAIN public token URL (unsigned),
 * UTM-tagged for attribution. From-address/name come from the applied SMTP config.
 */
class ReportPublishedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report)
    {
    }

    public function envelope(): Envelope
    {
        $petName = $this->report->petField('name');

        return new Envelope(
            subject: filled($petName)
                ? "{$petName}'s microbiome report is ready"
                : 'Your pet\'s microbiome report is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-published',
            with: [
                // report_url is the plain /report/{token} route (NOT signed), so
                // appending UTM params is safe.
                'url' => Utm::email($this->report->report_url, 'report_published', 'see_my_results'),
                'petName' => $this->report->petField('name'),
                'ownerName' => $this->report->petClient?->name,
            ],
        );
    }
}
