<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "your SMTP works" email sent from the Settings → Email (SMTP) "Send test
 * email" button. Renders through the SAME shared branded layout
 * (emails/layout.blade.php) as the welcome and password-reset emails, so all three
 * are identical in chrome (coloured logo, #4654A4 accent, footer) and differ only
 * in body copy. From-address/name come from the applied SMTP mail config.
 */
class TestSmtpEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Biome4Pets SMTP test email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test',
        );
    }
}
