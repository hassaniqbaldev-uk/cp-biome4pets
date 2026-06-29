<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\CatalogProduct;
use App\Models\Plan;
use App\Models\PlanVariant;
use App\Models\ProductRule;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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
                            ->helperText('Human-readable note only (shown to admins). The actual auto-recommendation logic is configured in the "Auto-recommendation" section below.')
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

                // Phase 2: client-configurable trigger → plan mapping. Replaces the
                // former hardcoded recommendPlanId() logic (read by the matcher and
                // the Settings → Trigger Rules explainer).
                Forms\Components\Section::make('Auto-recommendation')
                    ->description('How the report builder auto-selects this plan from the fired product triggers. Plans are checked in match-priority order (lowest first); the first plan with a satisfied condition wins.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('match_priority')
                            ->label('Match priority')
                            ->numeric()
                            ->default(1000)
                            ->required()
                            ->helperText('Lower = checked first. The first plan whose condition is met wins; ties break by record id (lower id first).'),
                        Forms\Components\Toggle::make('is_fallback')
                            ->label('Default fallback plan')
                            ->default(false)
                            ->helperText('Recommended only when NO triggers fire at all. Just one plan should be the fallback — saving this on enforces it (any other fallback is cleared).'),
                        Forms\Components\Repeater::make('trigger_conditions')
                            ->label('Trigger conditions')
                            ->helperText('Each row is an AND-set: every selected trigger must fire for the row to match. Multiple rows are OR-ed (any satisfied row selects this plan). No rows = never auto-recommended (it can still be picked manually), unless this is the fallback above.')
                            ->schema([
                                Forms\Components\Select::make('required_triggers')
                                    ->label('All of these triggers fire')
                                    ->multiple()
                                    ->options(fn (): array => ProductRule::triggerNameOptions())
                                    ->required()
                                    ->native(false),
                            ])
                            ->itemLabel(fn (array $state): ?string => filled($state['required_triggers'] ?? null)
                                ? implode(' + ', (array) $state['required_triggers'])
                                : 'New condition')
                            ->addActionLabel('Add condition')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Subscription (display only)')
                    ->description('Subscribe-panel content for the rendered plan. Pricing is a hardcoded display string — no discount is computed here.')
                    ->schema([
                        Forms\Components\Toggle::make('subscription_available')
                            ->label('Subscription Available')
                            ->default(true),
                        Forms\Components\TextInput::make('subscription_price')
                            ->label('Subscription Price')
                            ->maxLength(255)
                            ->helperText('Discounted display string, e.g. £29.75 / month.'),
                        Forms\Components\TextInput::make('subscription_full_price')
                            ->label('Full Price (struck through)')
                            ->maxLength(255)
                            ->helperText('Optional display string, e.g. £35 / month. Shown struck through next to the subscription price to convey the saving. Blank = no old→new shown.'),
                        Forms\Components\TextInput::make('subscription_billing_note')
                            ->label('Billing Note')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('subscription_saving_label')
                            ->label('Saving Label')
                            ->maxLength(255)
                            ->helperText('Optional badge, e.g. "15% off". Blank = no badge.'),
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
                                            // Text, not numeric: holds "3 (one pouch per month)".
                                            ->placeholder('e.g. 3 (one pouch per month)')
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

                static::conditionVariantsSection(),
            ]);
    }

    /**
     * The "Condition Variants" section: per-plan overrides keyed by a pet condition
     * (sensitive / large / both). Each variant can override the checkout link and
     * the bundle-price display, and swap individual plan products (e.g. AMR → AMR
     * Rosemary-Free) with optional dose/quantity/duration overrides. A plan with no
     * variants behaves exactly as before — this section is simply left empty.
     * Persistence + validation live in the ManagesPlanVariants trait on the pages.
     */
    public static function conditionVariantsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Condition Variants')
            ->description('Optional. Override this plan for a flagged pet: swap products, point to a different Loop checkout, and/or adjust the displayed price. A pet with no flags (or a plan with no variants) always gets the base plan above.')
            ->schema([
                Forms\Components\Repeater::make('variants')
                    ->label('Variants')
                    ->schema([
                        Forms\Components\Select::make('condition')
                            ->label('Condition')
                            ->options(PlanVariant::CONDITION_LABELS)
                            ->required()
                            ->native(false)
                            ->helperText('One variant per condition per plan. "Sensitive + Large" is the dedicated combined variant (a both-flagged pet needs its own link/dosage).'),
                        Forms\Components\Toggle::make('enabled')
                            ->label('Enabled')
                            ->default(true)
                            ->helperText('Disabled variants are ignored during resolution (treated as not defined).'),
                        Forms\Components\TextInput::make('subscription_url')
                            ->label('Checkout link override')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('The pre-built Loop checkout link for this variant. Leave blank to inherit the base plan\'s link. Combined conditions need their own dedicated Loop link.'),

                        // Product swaps — the core of a variant.
                        Forms\Components\Repeater::make('product_overrides')
                            ->label('Product swaps')
                            ->schema([
                                Forms\Components\Select::make('from_catalog_product_id')
                                    ->label('Replace (a product used in this plan)')
                                    ->options(fn (Forms\Get $get): array => static::planStepProductOptions($get))
                                    ->searchable()
                                    ->required()
                                    ->native(false),
                                Forms\Components\Select::make('to_catalog_product_id')
                                    ->label('With')
                                    ->options(fn (): array => CatalogProduct::active()->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->native(false),
                                Forms\Components\TextInput::make('dose')
                                    ->label('Dose override')
                                    ->maxLength(255)
                                    ->placeholder('Blank = inherit base'),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantity override')
                                    ->maxLength(255)
                                    ->placeholder('Blank = inherit base'),
                                Forms\Components\TextInput::make('duration')
                                    ->label('Duration override')
                                    ->maxLength(255)
                                    ->placeholder('Blank = inherit base'),
                            ])
                            ->itemLabel(fn (array $state): ?string => filled($state['from_catalog_product_id'] ?? null)
                                ? (CatalogProduct::find($state['from_catalog_product_id'])?->name ?? 'Product')
                                    .' → '.(CatalogProduct::find($state['to_catalog_product_id'] ?? null)?->name ?? '…')
                                : 'New swap')
                            ->addActionLabel('Add product swap')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull()
                            ->helperText('Swap a plan product for a variant-specific one. Dose / quantity / duration are optional overrides — blank inherits the base step\'s value.'),

                        // Advanced: bundle-price display overrides (collapsed — the
                        // common variant is just a link + swap).
                        Forms\Components\Section::make('Price overrides (optional)')
                            ->description('Only if this variant\'s bundle price differs. Blank = inherit the base plan.')
                            ->collapsed()
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('subscription_price')->label('Subscription Price')->maxLength(255),
                                Forms\Components\TextInput::make('subscription_full_price')->label('Full Price (struck)')->maxLength(255),
                                Forms\Components\TextInput::make('subscription_billing_note')->label('Billing Note')->maxLength(255),
                                Forms\Components\TextInput::make('subscription_saving_label')->label('Saving Label')->maxLength(255),
                            ]),

                        // Read-only "effective result" preview.
                        Forms\Components\Placeholder::make('variant_preview')
                            ->label('Effective result for this variant')
                            ->columnSpanFull()
                            ->content(fn (Forms\Get $get): HtmlString => static::variantPreviewHtml($get)),
                    ])
                    ->itemLabel(fn (array $state): ?string => PlanVariant::CONDITION_LABELS[$state['condition'] ?? null] ?? 'New variant')
                    ->addActionLabel('Add variant')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Options for a swap's "from" select: the catalogue products actually used in
     * this plan's steps (read from the live form state), so admins swap real plan
     * products. Falls back to all active products when no steps are present yet (so
     * the field is never empty/unusable); a swap for a non-plan product is caught by
     * save-time validation.
     *
     * @return array<int,string>
     */
    public static function planStepProductOptions(Forms\Get $get): array
    {
        // Up out of: product_overrides item → product_overrides → variants item →
        // variants → (root). Four levels to reach the plan-level `steps`.
        $steps = (array) ($get('../../../../steps') ?? []);

        $ids = collect($steps)
            ->flatMap(fn ($step): array => collect($step['products'] ?? [])->pluck('catalog_product_id')->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return CatalogProduct::active()->orderBy('name')->pluck('name', 'id')->all();
        }

        return CatalogProduct::whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The per-row "Effective result" preview: the checkout link that would be used
     * (variant override or inherited base) and the from→to product swaps, resolved
     * to catalogue names — so an admin can eyeball it against the intended Loop bundle.
     */
    public static function variantPreviewHtml(Forms\Get $get): HtmlString
    {
        $variantUrl = $get('subscription_url');
        $baseUrl = $get('../../subscription_url');
        $link = filled($variantUrl) ? $variantUrl : ($baseUrl ?: '(no link set)');
        $linkSource = filled($variantUrl) ? 'variant override' : 'inherits base plan';

        $rows = '';
        foreach ((array) $get('product_overrides') as $ov) {
            if (blank($ov['from_catalog_product_id'] ?? null)) {
                continue;
            }
            $from = CatalogProduct::find($ov['from_catalog_product_id'])?->name ?? '—';
            $to = CatalogProduct::find($ov['to_catalog_product_id'] ?? null)?->name ?? '…';
            $extra = collect([
                filled($ov['dose'] ?? null) ? 'dose: '.$ov['dose'] : null,
                filled($ov['quantity'] ?? null) ? 'qty: '.$ov['quantity'] : null,
                filled($ov['duration'] ?? null) ? 'duration: '.$ov['duration'] : null,
            ])->filter()->implode(' · ');
            $rows .= '<li>'.e($from).' &rarr; <strong>'.e($to).'</strong>'
                .($extra !== '' ? ' <span style="color:#9ca3af;">('.e($extra).')</span>' : '').'</li>';
        }
        if ($rows === '') {
            $rows = '<li style="color:#9ca3af;">No product swaps — base products used.</li>';
        }

        return new HtmlString(
            '<div style="font-size:.8rem;line-height:1.5;">'
            .'<div><strong>Checkout link:</strong> '.e($link)
            .' <span style="color:#9ca3af;">('.$linkSource.')</span></div>'
            .'<div style="margin-top:.4rem;"><strong>Products after swap:</strong></div>'
            .'<ul style="margin:.2rem 0 0;padding-left:1.1rem;list-style:disc;">'.$rows.'</ul>'
            .'</div>'
        );
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
                // Read-only mirror of recommendPlanId()'s precedence (Phase 1).
                // "#N · condition" makes the first-match-wins order explicit, since
                // the list sorts by display position, NOT recommendation order.
                Tables\Columns\TextColumn::make('recommended_when')
                    ->label('Auto-recommended when')
                    ->state(function (Plan $record): string {
                        $rule = ReportResource::planRecommendationRuleFor($record->key);

                        return $rule
                            ? '#' . $rule['order'] . ' · ' . $rule['condition']
                            : 'Not auto-recommended (manual only)';
                    })
                    ->badge()
                    ->color(fn (Plan $record): string => ReportResource::planRecommendationRuleFor($record->key) ? 'info' : 'gray')
                    ->wrap(),
                Tables\Columns\TextColumn::make('subscription_price')
                    ->label('Subscription')
                    // Free-text display string (e.g. "£29.75 / month"), not a
                    // numeric amount — show it verbatim, don't money()-format it.
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
