<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\CatalogProduct;
use App\Models\Plan;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Catalog & Plans';

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $modelLabel = 'Plan';

    // After Product Catalog within the Catalog & Plans group.
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Plan Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            // Stable identifier — locked once the plan exists.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Unique slug, e.g. "restore-rebalance". Cannot be changed after creation.'),
                        Forms\Components\Textarea::make('trigger_description')
                            ->label('Trigger Description')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('enabled')
                            ->label('Enabled')
                            ->default(true),
                        Forms\Components\Select::make('species_availability')
                            ->label('Species Availability')
                            ->options([
                                'dog' => 'Dog',
                                'cat' => 'Cat',
                                'both' => 'Both',
                            ])
                            ->default('both')
                            ->native(false)
                            ->required()
                            ->helperText('Kept for future use — the app is dog-only today, so the report builder shows all enabled plans regardless.'),
                        Forms\Components\Textarea::make('intro_guidance')
                            ->label('Intro Guidance')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Optional steer passed to the copy generator when writing the report intro.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Subscription (display only)')
                    ->description('Subscribe-panel content for the rendered plan. Pricing is a hardcoded display string — no discount is computed here.')
                    ->schema([
                        Forms\Components\Toggle::make('subscription_available')
                            ->label('Subscription Available')
                            ->default(true),
                        Forms\Components\TextInput::make('subscription_price')
                            ->label('Subscription Price')
                            ->maxLength(255)
                            ->helperText('Hardcoded display string, e.g. £35 / month'),
                        Forms\Components\TextInput::make('subscription_billing_note')
                            ->label('Billing Note')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('subscription_saving_label')
                            ->label('Saving Label')
                            ->maxLength(255)
                            ->helperText('Optional; blank = auto-compute from product prices.'),
                        Forms\Components\TextInput::make('subscription_url')
                            ->label('Subscribe URL')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Shopify subscribe link. Plan D has its own tier.'),
                        Forms\Components\Placeholder::make('subscription_includes_note')
                            ->label('Included Products')
                            ->content('Derived automatically from the "included" products in the steps below.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Steps')
                    ->schema([
                        Forms\Components\Repeater::make('steps')
                            ->label('Plan Steps')
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label('Step Type')
                                    ->options([
                                        'product' => 'Product',
                                        'prose' => 'Prose',
                                    ])
                                    ->default('product')
                                    ->required()
                                    ->live()
                                    ->native(false),
                                Forms\Components\TextInput::make('step_title')
                                    ->label('Step Title')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('stage_label')
                                    ->label('Stage Label')
                                    ->placeholder('e.g. Phase 1 · Months 1–3')
                                    ->maxLength(255),

                                // Prose steps: body/tip are normally generated at
                                // report time — here they're an optional template.
                                Forms\Components\Textarea::make('body')
                                    ->label('Body (optional template/override)')
                                    ->rows(3)
                                    ->columnSpanFull()
                                    ->visible(fn (Forms\Get $get): bool => $get('type') === 'prose'),
                                Forms\Components\Textarea::make('tip')
                                    ->label('Tip (optional template/override)')
                                    ->rows(2)
                                    ->columnSpanFull()
                                    ->visible(fn (Forms\Get $get): bool => $get('type') === 'prose'),

                                // Product steps: one or more catalogue products.
                                Forms\Components\Repeater::make('products')
                                    ->label('Products')
                                    ->visible(fn (Forms\Get $get): bool => $get('type') === 'product')
                                    ->schema([
                                        Forms\Components\Select::make('catalog_product_id')
                                            ->label('Catalog Product')
                                            ->options(fn (): array => CatalogProduct::active()->orderBy('name')->pluck('name', 'id')->all())
                                            ->searchable()
                                            ->required(),
                                        Forms\Components\TextInput::make('duration')
                                            ->label('Duration')
                                            ->placeholder('e.g. 3 months (12 weeks)')
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('quantity')
                                            ->label('Quantity')
                                            // Text, not numeric: holds "3 (one tub per month)".
                                            ->placeholder('e.g. 3 (one tub per month)')
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('dose')
                                            ->label('Dose')
                                            // Platform default from Settings → Plans / Generation;
                                            // falls back to the literal default when that setting is blank.
                                            ->default(fn (): string => Setting::get(Setting::DEFAULT_DOSE) ?: Setting::DEFAULT_DOSE_FALLBACK)
                                            ->maxLength(255),
                                        Forms\Components\Select::make('inclusion')
                                            ->label('Inclusion')
                                            ->options([
                                                'included' => 'Included',
                                                'optional' => 'Optional',
                                            ])
                                            ->default('included')
                                            ->required()
                                            ->native(false),
                                    ])
                                    ->itemLabel(fn (array $state): ?string => CatalogProduct::find($state['catalog_product_id'] ?? null)?->name ?? 'New product')
                                    ->addActionLabel('Add product')
                                    ->reorderable()
                                    ->defaultItems(0)
                                    ->columnSpanFull(),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['step_title'] ?? 'New step')
                            ->addActionLabel('Add step')
                            ->reorderable()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('key')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('species_availability')
                    ->label('Species')
                    ->badge(),
                Tables\Columns\IconColumn::make('enabled')
                    ->label('Enabled')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscription_price')
                    ->label('Subscription')
                    ->money('GBP')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->date(\App\Support\AdminFormatting::DATE)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
