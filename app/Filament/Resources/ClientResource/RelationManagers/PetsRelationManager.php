<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('breed')
                    ->searchable(),
                Tables\Columns\TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sex'),
                Tables\Columns\TextColumn::make('shopify_pet_id')
                    ->label('Shopify Pet ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reports_count')
                    ->label('Reports')
                    ->counts('reports'),
            ])
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
