<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ReportResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The /admin landing. Extends Filament's default dashboard (keeping its neutral
 * styling and the auto-discovered widgets) and adds quick-action buttons for the
 * two most common starts.
 */
class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_report')
                ->label('New report')
                ->icon('heroicon-m-document-plus')
                ->color('primary')
                ->url(ReportResource::getUrl('create')),

            Action::make('new_client')
                ->label('New client')
                ->icon('heroicon-m-user-plus')
                ->color('gray')
                ->url(ClientResource::getUrl('create')),
        ];
    }
}
