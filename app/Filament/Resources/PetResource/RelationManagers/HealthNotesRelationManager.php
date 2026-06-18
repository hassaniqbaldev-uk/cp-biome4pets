<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The pet's health-notes log (Part 1): a dated history of free-text notes and/or
 * weight readings. Sits on the Pet hub beside Tests. Each entry must carry at
 * least one of note/weight — enforced here and again at the model level.
 */
class HealthNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'healthNotes';

    protected static ?string $title = 'Health Notes';

    protected static ?string $recordTitleAttribute = 'date';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->label('Date')
                    ->default(today())
                    ->required(),
                // An entry can't be empty: require a note OR a weight. We use the
                // IMPLICIT required_without rule (mutual between the two fields) on
                // purpose — a plain closure rule is non-implicit and Laravel skips
                // it when the field itself is empty, which is exactly the case we
                // need to catch. required_without runs even on empty values, so the
                // user gets a clean inline error and the save is blocked before the
                // model-level guard is ever reached.
                Forms\Components\TextInput::make('weight_kg')
                    ->label('Weight (kg)')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->requiredWithout('note')
                    ->validationMessages([
                        'required_without' => 'Add a note or a weight — an entry can\'t be empty.',
                    ]),
                Forms\Components\Textarea::make('note')
                    ->label('Note')
                    ->rows(3)
                    ->columnSpanFull()
                    ->requiredWithout('weight_kg')
                    ->validationMessages([
                        'required_without' => 'Add a note or a weight — an entry can\'t be empty.',
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date(\App\Support\AdminFormatting::DATE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('weight_kg')
                    ->label('Weight')
                    ->formatStateUsing(fn ($state): string => filled($state) ? number_format((float) $state, 2) . ' kg' : '')
                    ->placeholder('—'),
            ])
            ->defaultSort('date', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateHeading('No health notes yet')
            ->emptyStateDescription('Record a note or a weight to start this pet\'s history.')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
