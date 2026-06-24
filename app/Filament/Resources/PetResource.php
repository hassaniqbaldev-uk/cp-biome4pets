<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\SoftDeletableResource;
use App\Filament\Resources\PetResource\Pages;
use App\Filament\Resources\PetResource\RelationManagers;
use App\Models\Pet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * A dedicated page for a Pet, primarily to host the under-pet Tests area (the
 * pet's sample history). The existing ClientResource → PetsRelationManager
 * remains the place to manage a client's pets inline; this resource adds a
 * first-class pet page where Tests live (Phase 3b).
 */
class PetResource extends Resource
{
    use SoftDeletableResource;

    protected static ?string $model = Pet::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'client.name'];
    }

    public static function form(Form $form): Form
    {
        // The editable pet form. On the hub (edit) this is shown only behind the
        // "Edit pet details" action — the default hub view is the read-only
        // header infolist (see EditPet). On CREATE it is the normal full form.
        // Two-column grid so the fields use the horizontal space, not stacked.
        return $form
            ->schema([
                Forms\Components\Section::make('Pet details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('client_id')
                            ->label('Owner')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('breed')
                            ->maxLength(255),
                        \App\Filament\Forms\PetProfileFields::yearOfBirth(),
                        Forms\Components\Select::make('sex')
                            ->options([
                                'Male' => 'Male',
                                'Female' => 'Female',
                            ]),
                        Forms\Components\Select::make('diet')
                            ->label('Diet Type')
                            ->options([
                                'Raw' => 'Raw',
                                'Kibble' => 'Kibble',
                                'Mixed' => 'Mixed',
                                'Home Cooked' => 'Home Cooked',
                                'Other' => 'Other',
                            ]),
                        ...\App\Filament\Forms\PetProfileFields::flags(),
                        Forms\Components\TextInput::make('shopify_pet_id')
                            ->label('Shopify Pet ID')
                            ->maxLength(255)
                            ->helperText('Reference ID from Shopify (optional)'),
                        // Health notes are now a dated log managed in the Health
                        // Notes relation manager below. On CREATE only, capture an
                        // optional first entry (note and/or weight); both blank ⇒
                        // no entry is created. Transient fields, not Pet columns
                        // (see CreatePet).
                        Forms\Components\Textarea::make('initial_note')
                            ->label('Initial note')
                            ->helperText('Optional. Recorded as the first health-log entry, dated today.')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visibleOn('create'),
                        Forms\Components\TextInput::make('initial_weight_kg')
                            ->label('Initial weight (kg)')
                            ->helperText('Optional. Recorded with the first health-log entry.')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            // Weight drives the Large breed flag (>= 35 kg).
                            ->live()
                            ->afterStateUpdated(\App\Filament\Forms\PetProfileFields::largeBreedFromWeight())
                            ->visibleOn('create'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('breed')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tests_count')
                    ->label('Tests')
                    ->counts('tests')
                    ->badge(),
                Tables\Columns\TextColumn::make('reports_count')
                    ->label('Reports')
                    ->counts('reports')
                    ->badge(),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-heart')
            ->emptyStateHeading('No pets yet')
            ->emptyStateDescription('Pets are added under their owner on the client hub, or create one here.')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Soft delete + Restore only — no force-delete in the UI (any role).
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TestsRelationManager::class,
            RelationManagers\HealthNotesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPets::route('/'),
            'create' => Pages\CreatePet::route('/create'),
            'edit' => Pages\EditPet::route('/{record}/edit'),
        ];
    }
}
