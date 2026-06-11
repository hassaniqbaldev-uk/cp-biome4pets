<?php

namespace App\Filament\Resources;

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
    protected static ?string $model = Pet::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Reports Management';

    protected static ?int $navigationSort = 2;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'client.name'];
    }

    public static function form(Form $form): Form
    {
        // Reuses the same pet fields as PetsRelationManager, plus the owning
        // client (which is implicit there but explicit on a standalone page).
        return $form
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
                Forms\Components\DatePicker::make('date_of_birth')
                    ->label('Date of Birth'),
                Forms\Components\Select::make('sex')
                    ->options([
                        'Male' => 'Male',
                        'Female' => 'Female',
                    ]),
                Forms\Components\Select::make('diet')
                    ->label('Diet Type')
                    ->options([
                        'Raw' => 'Raw',
                        'Processed' => 'Processed',
                        'Mixed' => 'Mixed',
                        'Other' => 'Other',
                    ]),
                Forms\Components\Textarea::make('health_notes')
                    ->label('Health Notes & Symptoms')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('shopify_pet_id')
                    ->label('Shopify Pet ID')
                    ->maxLength(255)
                    ->helperText('Reference ID from Shopify (optional)'),
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
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TestsRelationManager::class,
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
