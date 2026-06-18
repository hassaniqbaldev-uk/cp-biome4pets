<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Filament\Resources\ReportResource;
use App\Models\Report;
use App\Support\AdminFormatting;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only at-a-glance list of this client's reports on the Client hub. Reports
 * are created via the test → report flow (Pet hub), NOT here — so there is no
 * create/edit/delete. Each row links to the admin editor (row click + "Edit
 * report") and the public rendered report ("View report", new tab), mirroring
 * the Test row actions.
 */
class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    protected static ?string $title = 'Reports';

    protected static ?string $recordTitleAttribute = 'slug';

    // No inline editing here; reports are authored through the test flow.
    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Row click opens the admin report editor (same drill pattern as
            // the Pets manager → Pet hub).
            ->recordUrl(fn (Report $record): string => ReportResource::getUrl('edit', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('pet.name')
                    ->label('Pet')
                    ->searchable(),
                Tables\Columns\TextColumn::make('test.sample_id')
                    ->label('Sample ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('test.report_date')
                    ->label('Report Date')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminFormatting::reportLabel($state))
                    ->color(fn (?string $state): string => AdminFormatting::reportColor($state)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-document-chart-bar')
            ->emptyStateHeading('No reports yet')
            ->emptyStateDescription('Reports generated for this client\'s pets will appear here.')
            ->actions([
                Tables\Actions\Action::make('edit_report')
                    ->label('Edit report')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->url(fn (Report $record): string => ReportResource::getUrl('edit', ['record' => $record])),
                Tables\Actions\Action::make('view_report')
                    ->label('View report')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (Report $record): ?string => $record->report_url)
                    ->openUrlInNewTab(),
            ]);
    }
}
