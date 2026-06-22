<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\SoftDeletableResource;
use App\Filament\Resources\TestResource\Pages;
use App\Models\Test;
use App\Support\AdminFormatting;
use App\Support\PaidActionLimiter;
use App\Support\ReportGeneration;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A top-level list of all tests (sample analyses), so a sample can be found and
 * acted on without first knowing its pet — and, crucially, so a test is globally
 * searchable by sample/order ID even before it has a report (search previously
 * only surfaced tests via a Report that referenced them; a fresh test had no
 * searchable home). This resource is LIST/VIEW/MANAGE only: tests are created
 * under their pet (PetResource → Tests), so there is no create path here.
 */
class TestResource extends Resource
{
    use SoftDeletableResource;

    protected static ?string $model = Test::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Operations';

    // After Reports (2) and Pets (3) in the Operations group.
    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'sample_id';

    public static function getGloballySearchableAttributes(): array
    {
        // Findable by either reference, even with no report yet (both columns
        // live on the Test). order_id == sample_id today, but search both.
        return ['sample_id', 'order_id'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['pet.client']);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Pet' => $record->pet?->name ?? '—',
            'Owner' => $record->pet?->client?->name ?? '—',
            'State' => AdminFormatting::testStateLabel($record->hasReport()),
        ];
    }

    /**
     * Tests have no view/edit page (they are managed under their pet), so the
     * default result URL would be blank and Filament would drop the result.
     * Deep-link to the pet hub where the test lives instead.
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return $record->pet
            ? PetResource::getUrl('edit', ['record' => $record->pet])
            : static::getUrl('index');
    }

    // Tests are created under a pet (PetResource → Tests), never from scratch here.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['pet', 'pet.client']))
            ->columns([
                Tables\Columns\TextColumn::make('pet.name')
                    ->label('Pet')
                    ->weight('bold')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('owner')
                    ->label('Owner')
                    ->getStateUsing(fn (Test $record): ?string => $record->pet?->client?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('order_id')
                    ->label('Order / Test ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sample_id')
                    ->label('Sample ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('microbiome_classification')
                    ->label('Classification')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('report_date')
                    ->label('Test date')
                    ->date(AdminFormatting::DATE)
                    ->placeholder('—')
                    ->sortable(),
                // Derived state (the stored status column was dropped): "Reported"
                // once a report links the test, else "Awaiting report".
                Tables\Columns\TextColumn::make('reports_count')
                    ->label('State')
                    ->counts('reports')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => AdminFormatting::testStateLabel($state > 0))
                    ->color(fn (int $state): string => AdminFormatting::testStateColor($state > 0)),
            ])
            ->defaultSort('report_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('report_state')
                    ->label('Report status')
                    ->options([
                        'awaiting' => 'Awaiting report',
                        'has' => 'Has a report',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'awaiting' => $query->whereDoesntHave('reports'),
                        'has' => $query->whereHas('reports'),
                        default => $query,
                    }),
            ])
            ->emptyStateIcon('heroicon-o-beaker')
            ->emptyStateHeading('No tests yet')
            ->emptyStateDescription('Tests are added under a pet (Pets → the pet → Tests).')
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Mirrors the pet hub's generate/open actions, reusing the same
                // ReportGeneration path. Create when none exists, open when it does.
                Tables\Actions\Action::make('generate_report')
                    ->label('Generate report')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Generate report from this test')
                    ->modalDescription('Generates the AI interpretation and recommended plan from this test\'s lab data, then opens the report for review.')
                    ->visible(fn (Test $record): bool => $record->reports()->doesntExist())
                    ->action(function (Test $record) {
                        // L2: this runs the paid AI interpretation + plan copy — cap per admin.
                        if (PaidActionLimiter::exceeded('generate-ai', 10)) {
                            return null;
                        }

                        $report = ReportGeneration::createReportFromTest($record);

                        Notification::make()
                            ->title('Report generated')
                            ->body('Review the AI copy and apply a plan in the editor.')
                            ->success()
                            ->send();

                        return redirect(ReportResource::getUrl('edit', ['record' => $report->getKey()]));
                    }),
                Tables\Actions\Action::make('open_report')
                    ->label('Open report')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (Test $record): bool => $record->reports()->exists())
                    ->url(fn (Test $record): ?string => ($report = $record->reports()->latest()->first())
                        ? ReportResource::getUrl('edit', ['record' => $report->getKey()])
                        : null),
                // Soft delete + Restore only — force-delete (which would also wipe
                // the CSV via the forceDeleted hook) is NOT exposed in the UI.
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Test')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('pet.name')->label('Pet')->placeholder('—'),
                    Infolists\Components\TextEntry::make('pet.client.name')->label('Owner')->placeholder('—'),
                    Infolists\Components\TextEntry::make('order_id')->label('Order / Test ID'),
                    Infolists\Components\TextEntry::make('sample_id')->label('Sample ID'),
                    Infolists\Components\TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->getStateUsing(fn (Test $record): string => AdminFormatting::testStateLabel($record->hasReport()))
                        ->color(fn (Test $record): string => AdminFormatting::testStateColor($record->hasReport())),
                    Infolists\Components\TextEntry::make('report_date')->label('Test date')->date(AdminFormatting::DATE)->placeholder('—'),
                    Infolists\Components\TextEntry::make('csv_path')
                        ->label('Lab data (CSV)')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Download CSV' : '—')
                        ->url(fn (Test $record): ?string => filled($record->csv_path) ? route('admin.tests.csv', $record) : null)
                        ->openUrlInNewTab(),
                ]),
            Infolists\Components\Section::make('Parsed metrics')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('microbiome_classification')->label('Classification')->placeholder('—'),
                    Infolists\Components\TextEntry::make('diversity_score')->label('Diversity')->placeholder('—'),
                    Infolists\Components\TextEntry::make('species_richness')->label('Richness')->placeholder('—'),
                    Infolists\Components\TextEntry::make('dysbiosis_score')->label('Dysbiosis')->placeholder('—'),
                    Infolists\Components\KeyValueEntry::make('phylum_data')
                        ->label('Phylum breakdown (%)')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTests::route('/'),
        ];
    }
}
