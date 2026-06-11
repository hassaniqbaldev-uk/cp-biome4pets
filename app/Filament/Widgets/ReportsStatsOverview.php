<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
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

        $publishedCount = Report::where('status', 'published')->count();
        $draftCount = Report::where('status', 'draft')->count();

        return [
            Stat::make('Total Clients', Client::count())
                ->description('Registered owners')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Total Pets', Pet::count())
                ->description('Dogs on file')
                ->descriptionIcon('heroicon-m-heart')
                ->color('info'),

            Stat::make('Total Reports', Report::count())
                ->description('All microbiome reports')
                ->descriptionIcon('heroicon-m-document-chart-bar')
                ->color('primary'),

            Stat::make('Reports This Month', $reportsThisMonth)
                ->description($now->format('F Y'))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success'),

            Stat::make('Published Reports', $publishedCount)
                ->description($draftCount . ' ' . ($draftCount === 1 ? 'draft' : 'drafts') . ' pending')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('success'),

            Stat::make('Awaiting Publish', $draftCount)
                ->description('Drafts not yet published')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }
}
