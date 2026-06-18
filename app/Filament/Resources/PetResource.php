<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\PetResource\Pages;
use App\Filament\Resources\PetResource\RelationManagers;
use App\Models\Pet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

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
                // The pet fields live in a collapsible section. Collapsed by default
                // when EDITING (the hub is mostly for managing tests/notes, not
                // re-editing breed); expanded on CREATE so the initial note/weight
                // fields below are visible and usable.
                Forms\Components\Section::make('Pet details')
                    ->collapsible()
                    ->collapsed(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                // Linked "Owner" reference: drill UP from the pet hub to the
                // owning client's edit page. Edit-only (a new pet has no record
                // yet); the Select below is how the owner is set/reassigned.
                Forms\Components\Placeholder::make('owner_link')
                    ->label('Owner')
                    ->visible(fn (?Pet $record): bool => $record?->client !== null)
                    ->content(fn (?Pet $record): HtmlString => new HtmlString(
                        '<a href="' . ClientResource::getUrl('edit', ['record' => $record->client])
                        . '" class="fi-link font-medium text-primary-600 hover:underline dark:text-primary-400">'
                        . e($record->client->name) . ' &rarr;</a>'
                    )),
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
                // Health notes are now a dated log managed in the Health Notes
                // relation manager below. On CREATE only, capture an optional first
                // entry (note and/or weight); both blank ⇒ no entry is created.
                // These are transient form fields, not Pet columns (see CreatePet).
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
                    ->visibleOn('create'),
                Forms\Components\TextInput::make('shopify_pet_id')
                    ->label('Shopify Pet ID')
                    ->maxLength(255)
                    ->helperText('Reference ID from Shopify (optional)'),
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
