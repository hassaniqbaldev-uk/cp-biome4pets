<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportAnIssue;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Report an Issue is now an informational page that points to the floating
 * Feedbucket widget, with a CreativePixels agency credit. The old in-app form
 * (and its mailer) were removed.
 */
class ReportAnIssueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new User(['name' => 'Admin User', 'email' => 'admin@cp.agency', 'role' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_page_points_to_the_feedback_widget_and_shows_agency_logo(): void
    {
        Livewire::test(ReportAnIssue::class)
            ->assertOk()
            ->assertSee('floating white feedback bar')   // guidance copy
            ->assertSee('CreativePixels')                // logo alt text / credit
            ->assertSee(ReportAnIssue::AGENCY_LOGO)       // logo src
            ->assertDontSee('Subject');                   // old form field is gone
    }
}
