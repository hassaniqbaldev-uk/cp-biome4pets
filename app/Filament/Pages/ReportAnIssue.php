<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Report an Issue — an informational page that points the user at the floating
 * Feedbucket widget (already injected site-wide via the panel HEAD_END hook).
 * The old in-app feedback form and its IssueReported mailer were removed in
 * favour of that widget, which captures screenshots / video alongside the note.
 */
class ReportAnIssue extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?string $navigationLabel = 'Report an Issue';

    protected static ?string $title = 'Report an Issue';

    /**
     * Grouped under "System", listed after Settings.
     */
    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.report-an-issue';

    /** The CreativePixels agency credit logo shown on this page. */
    public const AGENCY_LOGO = 'https://cp.cpdev.uk/wp-content/uploads/2024/09/Logo-dark.svg';

    /**
     * Open to all admin-level staff (Admin AND Super Admin) — anyone managing the
     * platform can report an issue. canAccess() is the security gate (Filament
     * aborts 403 on direct URL access when false); shouldRegisterNavigation()
     * hides the nav for anyone who can't access it (e.g. the future client role).
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
