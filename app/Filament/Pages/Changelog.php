<?php

namespace App\Filament\Pages;

use App\Support\ChangelogReader;
use Filament\Pages\Page;

/**
 * Changelog — a READ-ONLY, in-portal view of CHANGELOG.md (the repo's release
 * record). It mirrors the error-log viewer: it reads a file and displays it, with
 * no add/edit/delete. The changelog ships with the code; new entries are added to
 * CHANGELOG.md as part of each release, and this page shows them automatically.
 *
 * Access: visible to Admins AND Super Admins (isAdmin() covers both roles). Unlike
 * the error logs, this is just a record of platform changes — not sensitive — so it
 * doesn't need the Super-Admin-only gate. It is still staff-only (never customer
 * facing): canAccess() 403s anyone who isn't an admin, and the nav item is hidden.
 */
class Changelog extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Changelog';

    protected static ?string $title = 'Changelog';

    protected static ?string $navigationGroup = 'System';

    // Near Settings (10) / Users (15) / Bulk Operations (16).
    protected static ?int $navigationSort = 17;

    protected static string $view = 'filament.pages.changelog';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Parsed versions (newest first) for the view. Empty when CHANGELOG.md is
     * missing or malformed — the view shows a friendly message for that.
     *
     * @return list<array{version:string, date:?string, groups:list<array{category:string, entries:list<string>}>}>
     */
    public function versions(): array
    {
        return ChangelogReader::versions();
    }
}
