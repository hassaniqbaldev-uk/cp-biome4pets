<?php

namespace App\Filament\Widgets;

use App\Models\Report;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ReportsPerMonthChart extends ChartWidget
{
    protected static ?string $heading = 'Reports Created — Last 6 Months';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $labels = [];
        $counts = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->startOfMonth()->subMonths($i);

            $labels[] = $month->format('M Y');
            $counts[] = Report::whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->count();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Reports created',
                    'data' => $counts,
                    // Biome4Pets report palette: line in #4168D5 (the first palette
                    // colour with enough contrast for a line stroke); translucent
                    // area fill from #6CE5E8 (the light cyan suits a fill).
                    'borderColor' => '#4168D5',
                    'backgroundColor' => 'rgba(108, 229, 232, 0.18)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
