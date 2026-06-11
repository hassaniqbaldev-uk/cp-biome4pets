<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IssueReported extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $issueSubject,
        public ?string $category,
        public string $description,
        public string $reporterName,
        public string $reporterEmail,
        public string $reportedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Biome4Pets Issue] '.$this->issueSubject,
            replyTo: [new Address($this->reporterEmail, $this->reporterName)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.issue-reported',
        );
    }
}
