<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\PetResource;
use App\Filament\Resources\ReportResource;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class ReportsStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $now = Carbon::now();

        $reportsThisMonth = Report::whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();

        // The work queue: tests with results but no linked report yet. Authoritative
        // condition = no related report (a report_generated test always has one, so
        // it's excluded; equals status results_received today).
        $awaitingReports = Test::query()->whereDoesntHave('reports')->count();

        return [
            Stat::make('Total Clients', Client::count())
                ->description('Registered owners')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(ClientResource::getUrl('index')),

            Stat::make('Total Pets', Pet::count())
                ->description('Dogs on file')
                ->descriptionIcon('heroicon-m-heart')
                ->color('info')
                ->url(PetResource::getUrl('index')),

            Stat::make('Reports This Month', $reportsThisMonth)
                ->description($now->format('F Y'))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success')
                ->url(ReportResource::getUrl('index')),

            // The actionable number. Not linked to a list (Tests have no top-level
            // resource) — the queue widget below is its destination.
            Stat::make('Tests Awaiting Reports', $awaitingReports)
                ->description('Results received, no report yet')
                ->descriptionIcon('heroicon-m-clock')
                ->color($awaitingReports > 0 ? 'warning' : 'gray'),
        ];
    }
}
