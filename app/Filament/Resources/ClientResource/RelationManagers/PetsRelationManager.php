<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Filament\Resources\PetResource;
use App\Models\Pet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PetsRelationManager extends RelationManager
{
    protected static string $relationship = 'pets';

    protected static ?string $title = 'Pets';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
                        'Kibble' => 'Kibble',
                        'Mixed' => 'Mixed',
                        'Other' => 'Other',
                    ]),
                ...\App\Filament\Forms\PetProfileFields::flags(),
                // Health notes are a dated log on the Pet hub. On CREATE only,
                // capture an optional first entry (note and/or weight); both blank
                // ⇒ no entry. Transient fields handled in the CreateAction below.
                Forms\Components\Textarea::make('initial_note')
                    ->label('Initial note')
                    ->helperText('Optional. Recorded as the first health-log entry, dated today.')
                    ->rows(3)
                    ->columnSpanFull()
                    ->visibleOn('create'),
                Forms\Components\TextInput::make('initial_weight_kg')
                    ->label('Initial weight (kg)')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    // Weight drives the Large breed flag (>= 35 kg).
                    ->live()
                    ->afterStateUpdated(\App\Filament\Forms\PetProfileFields::largeBreedFromWeight())
                    ->visibleOn('create'),
                Forms\Components\TextInput::make('shopify_pet_id')
                    ->label('Shopify Pet ID')
                    ->maxLength(255)
                    ->helperText('Reference ID from Shopify (optional)'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            // Drill DOWN into the pet hub: clicking a pet row opens the Pet edit
            // page (its hub), where that pet's Tests live. Inline create/edit/
            // delete actions below still work for quick management in place.
            ->recordUrl(fn (Pet $record): string => PetResource::getUrl('edit', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('breed')
                    ->searchable(),
                Tables\Columns\TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date(\App\Support\AdminFormatting::DATE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('sex'),
                Tables\Columns\TextColumn::make('shopify_pet_id')
                    ->label('Shopify Pet ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tests_count')
                    ->label('Tests')
                    ->counts('tests')
                    ->badge(),
                Tables\Columns\TextColumn::make('reports_count')
                    ->label('Reports')
                    ->counts('reports')
                    ->badge(),
            ])
            ->emptyStateIcon('heroicon-o-heart')
            ->emptyStateHeading('No pets yet')
            ->emptyStateDescription('Add this client\'s first pet to get started.')
            ->headerActions([
                // Create the pet, then write the optional first health-log entry.
                // initial_note/initial_weight_kg are transient (not Pet columns),
                // so they're lifted out before creating the pet.
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data): \App\Models\Pet {
                        $note = $data['initial_note'] ?? null;
                        $weight = $data['initial_weight_kg'] ?? null;
                        unset($data['initial_note'], $data['initial_weight_kg']);

                        /** @var \App\Models\Pet $pet */
                        $pet = $this->getOwnerRecord()->pets()->create($data);

                        if (filled($note) || filled($weight)) {
                            $pet->healthNotes()->create([
                                'date' => today(),
                                'note' => filled($note) ? $note : null,
                                'weight_kg' => filled($weight) ? $weight : null,
                            ]);
                        }

                        return $pet;
                    }),
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
