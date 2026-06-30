<?php

namespace App\Filament\Pages;

use App\Support\LogReader;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Error Logs — a Super-Admin-only viewer for the application log files under
 * storage/logs, so production errors can be diagnosed in-portal without SSH or
 * downloading log files.
 *
 * Reached UNDER Settings: it's nested beneath the Settings nav item
 * (navigationParentItem), matching where the client expects to find it, while
 * staying its own page/URL. Reading is read-only (tail + whitelist resolution in
 * LogReader); the one mutation is the manual "Clear logs" action below, which
 * truncates the selected log in place (never auto-on-deploy — a deliberate button
 * so a deploy error's evidence is never wiped automatically).
 *
 * Gating mirrors Settings / Bulk Operations: canAccess() is the security gate
 * (Filament 403s on direct URL access for Admins) and shouldRegisterNavigation()
 * hides the nav item for anyone who can't access it.
 */
class LogViewer extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Error Logs';

    protected static ?string $title = 'Error Logs';

    protected static ?string $navigationGroup = 'System';

    // Nested under the Settings nav item so it's reached via Settings.
    protected static ?string $navigationParentItem = 'Settings';

    // Between Bulk Operations (16) and Report an Issue (20).
    protected static ?int $navigationSort = 18;

    protected static string $view = 'filament.pages.log-viewer';

    /** Selected log file (a basename within storage/logs); bound to the picker. */
    public ?string $file = null;

    /** Show only error-and-worse levels (default) vs every logged level. */
    public bool $errorsOnly = true;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        // Default to the most-recently-modified log file (first in the list).
        $this->file = $this->files()[0] ?? null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Re-render re-reads the log tail, so a no-op action is a clean "refresh".
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => null),

            // Manual one-off "clean slate" — truncates the SELECTED log in place.
            // Deliberately NOT auto-on-deploy (that would wipe the evidence right
            // when a deploy error needs diagnosing). Confirm-gated + Super-Admin-only
            // (the whole page is already Super-Admin-only; canAccess() re-checked in
            // the handler for defence in depth). Hidden when there's no readable file
            // to clear.
            Action::make('clear')
                ->label('Clear logs')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Clear the error log?')
                ->modalDescription('This permanently removes the current log entries. Do this after reviewing — it cannot be undone.')
                ->modalSubmitActionLabel('Clear logs')
                ->visible(fn (): bool => filled($this->file) && ! $this->selectedFileUnreadable())
                ->action(function (): void {
                    if (! static::canAccess()) {
                        return; // Super-Admin-only; never clear for anyone else.
                    }

                    if (LogReader::clear($this->file)) {
                        Notification::make()
                            ->title('Error log cleared')
                            ->body('The log file is now empty. New entries will appear here as they\'re logged.')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Could not clear the log')
                            ->body('The selected log file could not be cleared. Pick a valid log file and try again.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Available log files for the picker (basenames, most recent first).
     *
     * @return list<string>
     */
    public function files(): array
    {
        return LogReader::availableFiles();
    }

    /**
     * Parsed entries for the selected file (newest first), filtered to error
     * levels unless "errors only" is off. Empty when no file is selected or the
     * file is missing/unreadable — the view shows a friendly message for that.
     *
     * @return list<array{timestamp:string, env:string, level:string, message:string, stack:string}>
     */
    public function entries(): array
    {
        $path = LogReader::resolve($this->file);

        if ($path === null) {
            return [];
        }

        $entries = LogReader::entries($path);

        if ($this->errorsOnly) {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => in_array($entry['level'], LogReader::ERROR_LEVELS, true),
            ));
        }

        return $entries;
    }

    /** True when a file is selected but cannot be read (missing/permissions). */
    public function selectedFileUnreadable(): bool
    {
        return filled($this->file) && LogReader::resolve($this->file) === null;
    }
}
