<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PetResource;
use App\Filament\Resources\ReportResource;
use App\Models\Test;
use App\Support\AdminFormatting;
use App\Support\ReportGeneration;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The actionable queue: tests that have results but no report yet. Each row links
 * to the pet hub's Tests section and offers a one-click "Generate report" (the
 * same path as the Tests relation manager, reusing ReportGeneration).
 */
class TestsAwaitingReportsTable extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Tests Awaiting Reports')
            ->description('Results received but no report generated yet. Work through these.')
            ->query(
                Test::query()
                    ->whereDoesntHave('reports')
                    ->with(['pet', 'pet.client'])
                    ->orderByDesc('report_date')
            )
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Every test with results already has a report.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                Tables\Columns\TextColumn::make('pet.name')
                    ->label('Pet')
                    ->weight('bold')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('owner')
                    ->label('Owner')
                    ->getStateUsing(fn (Test $record): ?string => $record->pet?->client?->name)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('order_id')
                    ->label('Order / Test ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('report_date')
                    ->label('Test date')
                    ->date(AdminFormatting::DATE)
                    ->placeholder('—')
                    ->sortable(),
                // Every row here is awaiting a report by definition (the query is
                // whereDoesntHave('reports')), so no state column is needed.
            ])
            // Click a row to open the pet hub (where the Tests section lives).
            ->recordUrl(fn (Test $record): ?string => $record->pet
                ? PetResource::getUrl('edit', ['record' => $record->pet])
                : null)
            ->actions([
                Tables\Actions\Action::make('generate_report')
                    ->label('Generate report')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Generate report from this test')
                    ->modalDescription('Generates the AI interpretation and recommended plan, then opens the report for review.')
                    ->action(function (Test $record) {
                        $report = ReportGeneration::createReportFromTest($record);

                        Notification::make()
                            ->title('Report generated')
                            ->body('Review the AI copy and apply a plan in the editor.')
                            ->success()
                            ->send();

                        return redirect(ReportResource::getUrl('edit', ['record' => $report->getKey()]));
                    }),
            ]);
    }
}
