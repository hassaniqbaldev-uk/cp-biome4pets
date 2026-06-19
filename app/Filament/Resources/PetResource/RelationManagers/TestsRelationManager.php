<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use App\Filament\Resources\ReportResource;
use App\Models\Test;
use App\Services\LabResultParser;
use App\Support\AdminFormatting;
use App\Support\ReportGeneration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * The pet's Tests (sample history). Mirrors the Client → PetsRelationManager
 * pattern. Phase 3b: create + view tests with raw lab data parsed onto the Test.
 * A Test can exist with NO report yet (status "Results received"). This phase
 * does NOT generate reports — that's 3c.
 */
class TestsRelationManager extends RelationManager
{
    protected static string $relationship = 'tests';

    protected static ?string $title = 'Tests';

    protected static ?string $recordTitleAttribute = 'order_id';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('order_id')
                    ->label('Order / Test ID')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Entered manually for now. Used as the test\'s sample reference too.'),
                // A test's single date is when the results came in (its sample /
                // results date) — NOT a "report date" (a report may not exist yet).
                // Stored in report_date, which report generation reads as its as-of.
                Forms\Components\DatePicker::make('report_date')
                    ->label('Test date')
                    ->helperText('The date the lab results are dated / came in.')
                    ->default(now()),

                // The CSV *is* the test's lab data — uploading it is how a test's
                // metrics are captured. Parsed automatically on upload (no button
                // to remember); re-parsed on save so the stored data always matches.
                Forms\Components\FileUpload::make('csv_path')
                    ->label('Lab data (CSV)')
                    ->acceptedFileTypes(['text/csv', '.csv'])
                    ->directory('csv')
                    ->disk('public')
                    ->maxSize(10240)
                    ->live()
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => ReportResource::parseCsvIntoForm($state, $set))
                    ->columnSpanFull()
                    ->helperText('Upload the lab CSV — it is parsed automatically and the metrics appear below.'),

                Forms\Components\Placeholder::make('parsed_preview')
                    ->label('Parsed metrics')
                    ->columnSpanFull()
                    ->content(function (Forms\Get $get): string {
                        $class = $get('microbiome_classification');
                        if (blank($class)) {
                            return 'No metrics yet — upload the lab CSV above and it will be parsed automatically.';
                        }

                        return '✓ Processed — Classification: ' . $class
                            . '  ·  Diversity: ' . $get('diversity_score')
                            . '  ·  Richness: ' . $get('species_richness')
                            . '  ·  Dysbiosis: ' . $get('dysbiosis_score');
                    }),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_id')
            ->columns([
                Tables\Columns\TextColumn::make('order_id')
                    ->label('Order / Test ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sample_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_date')
                    ->label('Test date')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('microbiome_classification')
                    ->label('Classification')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('diversity_score')
                    ->label('Diversity')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—'),
                // Derived state (the stored status column was dropped): a test is
                // "Reported" once a report links it, else "Awaiting report".
                Tables\Columns\TextColumn::make('reports_count')
                    ->label('State')
                    ->counts('reports')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => AdminFormatting::testStateLabel($state > 0))
                    ->color(fn (int $state): string => AdminFormatting::testStateColor($state > 0)),
            ])
            ->defaultSort('report_date', 'desc')
            ->emptyStateIcon('heroicon-o-beaker')
            ->emptyStateHeading('No tests yet')
            ->emptyStateDescription('Add a test and upload its lab CSV to get started.')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => $this->prepareTestData($data)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(fn (Infolist $infolist): Infolist => $this->testInfolist($infolist)),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => $this->prepareTestData($data)),
                // Entry A: generate a report FROM this test. Guarded to one report
                // per test — when a report already exists the action is hidden and
                // the report is reachable from the View dialog instead.
                Tables\Actions\Action::make('generate_report')
                    ->label('Generate report')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Generate report from this test')
                    ->modalDescription('Generates the AI interpretation and recommended plan from this test\'s lab data, then opens the report for review. The plan is applied in the editor.')
                    ->visible(fn (Test $record): bool => $record->reports()->doesntExist())
                    ->action(function (Test $record) {
                        $report = ReportGeneration::createReportFromTest($record);

                        Notification::make()
                            ->title('Report generated')
                            ->body('Review the AI copy and apply a plan in the editor.')
                            ->success()
                            ->send();

                        return redirect(ReportResource::getUrl('edit', ['record' => $report->getKey()]));
                    }),
                // Inverse of generate_report: when a report already exists, give
                // direct access to it. One-per-test is enforced by the generate
                // guard; if several somehow exist we target the latest.
                Tables\Actions\Action::make('edit_report')
                    ->label('Edit report')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (Test $record): bool => $record->reports()->exists())
                    ->url(fn (Test $record): ?string => ($report = $record->reports()->latest()->first())
                        ? ReportResource::getUrl('edit', ['record' => $report->getKey()])
                        : null),
                Tables\Actions\Action::make('view_report')
                    ->label('View report')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn (Test $record): bool => $record->reports()->exists())
                    ->url(fn (Test $record): ?string => optional($record->reports()->latest()->first())->report_url)
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Normalise the upload, (re)parse the CSV onto the test, and auto-derive
     * sample_id (== order_id) and client_id (from the owning pet). Runs on both
     * create and edit so the raw lab data always reflects the current CSV.
     */
    protected function prepareTestData(array $data): array
    {
        if (is_array($data['csv_path'] ?? null)) {
            $data['csv_path'] = array_values($data['csv_path'])[0] ?? null;
        }

        // Re-parse the stored CSV so the raw fields are persisted regardless of
        // whether "Process CSV" was clicked (mirrors the report create flow).
        if (! empty($data['csv_path'])) {
            $abs = Storage::disk('public')->path($data['csv_path']);
            if (is_file($abs)) {
                $data = array_merge($data, (new LabResultParser())->fromPath($abs));
            }
        }

        $data['sample_id'] = $data['order_id'] ?? ($data['sample_id'] ?? null);
        $data['client_id'] = $this->getOwnerRecord()->client_id;

        return $data;
    }

    protected function testInfolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Test')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('order_id')->label('Order / Test ID'),
                    Infolists\Components\TextEntry::make('sample_id'),
                    Infolists\Components\TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->getStateUsing(fn (Test $record): string => AdminFormatting::testStateLabel($record->hasReport()))
                        ->color(fn (Test $record): string => AdminFormatting::testStateColor($record->hasReport())),
                    Infolists\Components\TextEntry::make('report_date')->label('Test date')->date(AdminFormatting::DATE)->placeholder('—'),
                    Infolists\Components\TextEntry::make('csv_path')->label('Lab data (CSV)')->placeholder('—'),
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
            Infolists\Components\Section::make('Reports generated from this test')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('reports')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('sample_id')
                                ->label('Report')
                                ->url(fn ($record) => ReportResource::getUrl('edit', ['record' => $record]))
                                ->openUrlInNewTab(),
                            Infolists\Components\TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => AdminFormatting::reportLabel($state))
                                ->color(fn (?string $state): string => AdminFormatting::reportColor($state)),
                            Infolists\Components\TextEntry::make('created_at')->dateTime(AdminFormatting::DATE_TIME),
                        ])
                        ->columns(3),
                    Infolists\Components\TextEntry::make('no_reports')
                        ->hiddenLabel()
                        ->state('No report has been generated from this test yet.')
                        ->visible(fn (Test $record): bool => $record->reports()->doesntExist()),
                ]),
        ]);
    }
}
