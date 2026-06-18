<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReportResource;
use App\Models\Report;
use App\Support\AdminFormatting;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestReportsTable extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Reports')
            ->query(
                Report::query()
                    ->with(['pet', 'pet.client', 'client', 'test'])
                    ->latest()
                    ->limit(10)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('pet.name')
                    ->label('Pet')
                    ->weight('bold')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('owner')
                    ->label('Owner')
                    ->getStateUsing(fn (Report $record): ?string => $record->petClient?->name)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('test.sample_id')
                    ->label('Sample ID')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminFormatting::reportLabel($state))
                    ->color(fn (?string $state): string => AdminFormatting::reportColor($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
            ])
            ->recordUrl(
                fn (Report $record): string => ReportResource::getUrl('edit', ['record' => $record]),
            );
    }
}
