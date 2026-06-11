<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportAnIssue;
use App\Mail\IssueReported;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ReportAnIssueTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Build an admin in memory (no DB required) and act as them so the
        // page can auto-capture their name/email.
        $this->admin = new User([
            'name' => 'Admin User',
            'email' => 'admin@cp.agency',
        ]);

        $this->actingAs($this->admin);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_submitting_sends_issue_email_to_info_address_with_reply_to(): void
    {
        Mail::fake();

        Livewire::test(ReportAnIssue::class)
            ->set('data.subject', 'Dashboard crashes on load')
            ->set('data.category', 'Bug')
            ->set('data.description', 'The reports dashboard throws a 500 when opened.')
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Your issue has been reported');

        Mail::assertSent(IssueReported::class, function (IssueReported $mail) {
            $envelope = $mail->envelope();
            $replyTo = $envelope->replyTo[0]->address ?? null;

            return $mail->hasTo('info@cp.agency')
                && $replyTo === 'admin@cp.agency'
                && $envelope->subject === '[Biome4Pets Issue] Dashboard crashes on load'
                && $mail->issueSubject === 'Dashboard crashes on load'
                && $mail->category === 'Bug'
                && $mail->description === 'The reports dashboard throws a 500 when opened.'
                && $mail->reporterName === 'Admin User'
                && $mail->reporterEmail === 'admin@cp.agency'
                && $mail->reportedAt !== '';
        });
    }

    public function test_form_clears_after_successful_submit(): void
    {
        Mail::fake();

        Livewire::test(ReportAnIssue::class)
            ->set('data.subject', 'Something broke')
            ->set('data.description', 'Details here.')
            ->call('submit')
            ->assertSet('data.subject', null)
            ->assertSet('data.description', null);
    }

    public function test_subject_and_description_are_required(): void
    {
        Mail::fake();

        Livewire::test(ReportAnIssue::class)
            ->set('data.subject', '')
            ->set('data.description', '')
            ->call('submit')
            ->assertHasFormErrors([
                'subject' => 'required',
                'description' => 'required',
            ]);

        Mail::assertNothingSent();
    }

    public function test_mail_failure_shows_graceful_error_without_crashing(): void
    {
        // Force the mail send to throw, simulating unconfigured SMTP.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP not configured'));

        Livewire::test(ReportAnIssue::class)
            ->set('data.subject', 'Connectivity issue')
            ->set('data.description', 'Mail server is down.')
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Could not send your report');
    }
}
