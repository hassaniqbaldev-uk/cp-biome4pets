<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use App\Support\AdminFormatting;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reports list shows Draft → Published → Sent in a single status column.
 * "Sent" is DERIVED (never stored) from the send timestamps: a report is "Sent"
 * when it is published AND has been successfully delivered via EITHER channel
 * (Klaviyo or the App). A FAILED send stamps a timestamp but must not read as sent.
 */
class ReportSentStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function makeReport(string $status = 'published'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(),
            'sample_id' => 'S'.uniqid(), 'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => $status, 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
    }

    public function test_display_status_progresses_draft_published_sent(): void
    {
        $draft = $this->makeReport('draft');
        $this->assertSame('draft', $draft->displayStatus());
        $this->assertFalse($draft->hasBeenSent());

        $published = $this->makeReport('published');
        $this->assertSame('published', $published->displayStatus());
        $this->assertFalse($published->hasBeenSent());
    }

    public function test_successful_app_send_makes_it_sent(): void
    {
        $report = $this->makeReport('published');
        $report->recordAppSend(true, 'delivered');

        $this->assertTrue($report->hasBeenSent());
        $this->assertSame('sent', $report->displayStatus());
    }

    public function test_successful_klaviyo_send_makes_it_sent(): void
    {
        $report = $this->makeReport('published');
        $report->recordKlaviyoSend(true, 'queued');

        $this->assertTrue($report->hasBeenSent());
        $this->assertSame('sent', $report->displayStatus());
    }

    public function test_failed_send_does_not_read_as_sent(): void
    {
        // A failed attempt stamps *_last_sent_at but result ok=false → still Published.
        $report = $this->makeReport('published');
        $report->recordKlaviyoSend(false, 'bounced');
        $this->assertFalse($report->hasBeenSent());
        $this->assertSame('published', $report->displayStatus());

        $report->recordAppSend(false, 'smtp error');
        $this->assertFalse($report->fresh()->hasBeenSent());
        $this->assertSame('published', $report->fresh()->displayStatus());
    }

    public function test_sent_badge_uses_a_distinct_colour(): void
    {
        $this->assertSame('gray', AdminFormatting::reportColor('draft'));
        $this->assertSame('success', AdminFormatting::reportColor('published'));
        $this->assertSame('info', AdminFormatting::reportColor('sent'));
        $this->assertSame('Sent', AdminFormatting::reportLabel('sent'));
    }

    public function test_reports_table_shows_the_three_states(): void
    {
        $draft = $this->makeReport('draft');
        $published = $this->makeReport('published');
        $sent = $this->makeReport('published');
        $sent->recordAppSend(true, 'delivered');

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$draft, $published, $sent])
            ->assertSee('Draft')
            ->assertSee('Published')
            ->assertSee('Sent');
    }
}
