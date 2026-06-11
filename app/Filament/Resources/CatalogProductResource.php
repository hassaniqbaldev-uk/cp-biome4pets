<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogProductResource\Pages;
use App\Models\CatalogProduct;
use App\Models\ProductRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CatalogProductResource extends Resource
{
    protected static ?string $model = CatalogProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Reports Management';

    protected static ?string $navigationLabel = 'Product Catalog';

    protected static ?string $modelLabel = 'Catalog Product';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('url')
                    ->label('Product URL')
                    ->maxLength(500),
                Forms\Components\TextInput::make('image_path')
                    ->label('Image URL')
                    ->placeholder('https://example.com/image.jpg')
                    ->maxLength(500),
                Forms\Components\TextInput::make('price')
                    ->label('Price')
                    ->numeric()
                    ->prefix('£')
                    ->minValue(0)
                    ->nullable()
                    ->maxLength(20),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\CheckboxList::make('triggers')
                    ->label('Trigger Codes')
                    // Dynamic, shared list: the distinct trigger names defined in
                    // the Trigger Rules (Settings). Adding a rule makes its trigger
                    // taggable here automatically.
                    ->options(fn (): array => ProductRule::triggerNameOptions())
                    ->helperText('Defined under Settings → Trigger Rules.')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->money('GBP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('triggerEntries.trigger')
                    ->label('Triggers')
                    ->badge()
                    ->separator(','),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogProducts::route('/'),
            'create' => Pages\CreateCatalogProduct::route('/create'),
            'edit' => Pages\EditCatalogProduct::route('/{record}/edit'),
        ];
    }
}
