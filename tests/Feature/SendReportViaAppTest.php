<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Mail\ReportPublishedMail;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The new "Send via App" channel: a branded report email sent directly via SMTP
 * to the pet owner. Renders through the shared layout (chrome identical to
 * welcome/reset), CTA links to the plain (unsigned) token URL with UTM, records
 * the send, and degrades to an error toast (never a crash) when there's no email.
 */
class SendReportViaAppTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function makeReport(string $email = 'owner@example.com'): Report
    {
        $client = Client::create(['name' => 'Jane Owner', 'email' => $email]);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'order_id' => 'APP-1', 'sample_id' => 'APP-1', 'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => 'published', 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
    }

    public function test_app_send_emails_the_client_and_records_success(): void
    {
        Mail::fake();
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_app')
            ->assertNotified('Report sent');

        Mail::assertSent(ReportPublishedMail::class, fn (ReportPublishedMail $m): bool => $m->hasTo('owner@example.com'));

        $fresh = $report->fresh();
        $this->assertNotNull($fresh->app_last_sent_at);
        $this->assertTrue($fresh->app_last_result['ok']);
        $this->assertStringContainsString('OK', $fresh->appLastSentSummary());
    }

    public function test_app_send_with_no_client_email_shows_error_and_sends_nothing(): void
    {
        Mail::fake();
        $report = $this->makeReport('');   // client with no email

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_app')
            ->assertNotified('Cannot send');

        Mail::assertNothingSent();
        $this->assertNull($report->fresh()->app_last_sent_at);   // not recorded as sent
    }

    public function test_app_email_uses_shared_layout_with_bullets_cta_and_utm(): void
    {
        $report = $this->makeReport();

        $html = (new ReportPublishedMail($report))->render();

        // Shared chrome — identical to welcome/reset/test.
        $this->assertStringContainsString('biome4pets-logo.png', $html);     // coloured logo
        $this->assertStringContainsString('#4654A4', $html);                 // accent + button
        $this->assertStringContainsString('info@biome4pets.com', $html);     // footer

        // Mirrored body content.
        $this->assertStringContainsString('Personalised microbiome insights', $html);
        $this->assertStringContainsString("Analysis of your pet's gut health", $html);
        $this->assertStringContainsString('Areas that may benefit from support', $html);
        $this->assertStringContainsString('Recommended next steps based on the results', $html);
        $this->assertStringContainsString('We hope these insights help you make more informed decisions', $html);
        $this->assertStringContainsString('See my results', $html);
        $this->assertStringContainsString('Biscuit', $html);                 // personalised

        // CTA → plain token URL, UTM-tagged, and crucially NOT signed.
        $this->assertStringContainsString('/report/'.$report->public_token, $html);
        $this->assertStringContainsString('utm_medium=email', $html);
        $this->assertStringContainsString('utm_campaign=report_published', $html);
        $this->assertStringNotContainsString('signature=', $html);
    }
}
