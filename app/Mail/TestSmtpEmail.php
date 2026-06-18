<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A plain "your SMTP works" email sent from the Email & Integrations screen's
 * "Send test email" button. From-address/name come from the applied mail config
 * (the saved SMTP settings). British English, no em dashes.
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
            htmlString: '<p>Hello,</p>'
                . '<p>This is a test email confirming that your Biome4Pets portal SMTP settings are working.</p>'
                . '<p>If you received this, outbound email is configured correctly.</p>'
                . '<p>Thanks,<br>The Biome4Pets team</p>',
        );
    }
}
