<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportResource\Pages;
use App\Models\CatalogProduct;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\OpenAiService;
use App\Support\AdminFormatting;
use App\Support\PetFindings;
use App\Support\ReportGeneration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'sample_id';

    public static function getGloballySearchableAttributes(): array
    {
        // sample_id now lives on the linked Test (the record title resolves it
        // through the Report→Test proxy via getRecordTitleAttribute).
        return ['pet.name', 'test.sample_id', 'client.email'];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Wizard::make([
                    Forms\Components\Wizard\Step::make('Client & Pet Details')
                        ->schema([
                            Forms\Components\Select::make('client_id')
                                ->label('Client')
                                ->relationship('client', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (Forms\Set $set) => $set('pet_id', null))
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('name')
                                        ->required()
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('email')
                                        ->email()
                                        ->required()
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('phone')
                                        ->tel()
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('order_number')
                                        ->maxLength(255),
                                ]),
                            Forms\Components\Select::make('pet_id')
                                ->label('Pet')
                                ->options(fn (Forms\Get $get): array => \App\Models\Pet::query()
                                    ->where('client_id', $get('client_id'))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->disabled(fn (Forms\Get $get): bool => blank($get('client_id')))
                                ->helperText('Select a client first, then choose one of their dogs.')
                                ->createOptionForm([
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
                                ])
                                ->createOptionUsing(function (array $data, Forms\Get $get): int {
                                    $data['client_id'] = $get('client_id');

                                    return \App\Models\Pet::create($data)->getKey();
                                }),

                            // Phase 3c: every report attaches to a Test. Either reuse
                            // an existing report-less test for this pet, or upload a
                            // new CSV (which creates a Test on save).
                            Forms\Components\Radio::make('test_source')
                                ->label('Lab data source')
                                ->options([
                                    'new' => 'Upload a new CSV (creates a test)',
                                    'existing' => 'Use an existing test (no report yet)',
                                ])
                                ->default('new')
                                ->live()
                                ->dehydrated(false),

                            Forms\Components\Select::make('existing_test_id')
                                ->label('Existing test')
                                ->visible(fn (Forms\Get $get): bool => $get('test_source') === 'existing')
                                ->options(fn (Forms\Get $get): array => Test::query()
                                    ->where('pet_id', $get('pet_id'))
                                    ->whereDoesntHave('reports')
                                    ->orderByDesc('report_date')
                                    ->get()
                                    ->mapWithKeys(fn (Test $t): array => [$t->id => trim(
                                        $t->order_id
                                        . ($t->report_date ? ' · ' . $t->report_date->format('j M Y') : '')
                                        . ($t->microbiome_classification ? ' · ' . $t->microbiome_classification : '')
                                    )])
                                    ->all())
                                ->searchable()
                                ->native(false)
                                ->live()
                                ->helperText('Only tests for this pet that don\'t already have a report are listed.')
                                ->afterStateUpdated(function ($state, Forms\Set $set): void {
                                    static::loadTestIntoForm($state, $set);
                                }),

                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('generate_from_test')
                                    ->label('Generate AI from selected test')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('primary')
                                    ->visible(fn (Forms\Get $get): bool => $get('test_source') === 'existing' && filled($get('existing_test_id')))
                                    ->action(function (Forms\Get $get, Forms\Set $set): void {
                                        $test = Test::find($get('existing_test_id'));
                                        if (! $test) {
                                            Notification::make()->title('Select a test first')->warning()->send();
                                            return;
                                        }

                                        static::loadTestIntoForm($test->getKey(), $set);

                                        $interp = ReportGeneration::interpretationColumns(
                                            $test->phylum_data ?? [],
                                            $test->diversity_score,
                                            $test->pet,
                                            // Notes history as of the test's date.
                                            $test->report_date ?? $test->collected_at,
                                        );
                                        foreach ($interp as $key => $value) {
                                            $set($key, $value);
                                        }

                                        $selection = ReportGeneration::productSelection(
                                            $test->phylum_data ?? [],
                                            $test->diversity_score,
                                        );
                                        $set('catalog_product_ids', $selection['catalog_product_ids']);
                                        $set('plan_id', $selection['plan_id']);

                                        $allEmpty = collect($interp)->every(fn ($v) => empty($v));
                                        Notification::make()
                                            ->title($allEmpty ? 'Test loaded (AI returned empty)' : 'Test loaded and AI generated')
                                            ->body($allEmpty ? 'Check the OpenAI key/credits; you can edit copy manually.' : 'Review the interpretation and plan steps.')
                                            ->{$allEmpty ? 'warning' : 'success'}()
                                            ->send();
                                    }),
                            ])->visible(fn (Forms\Get $get): bool => $get('test_source') === 'existing'),

                            Forms\Components\TextInput::make('sample_id')
                                ->label('Sample / Order ID')
                                ->required()
                                ->maxLength(255)
                                ->helperText('For a new CSV this becomes the test\'s Order ID. For an existing test it is filled in for you.'),
                            Forms\Components\DatePicker::make('report_date')
                                ->required(),
                        ]),

                    Forms\Components\Wizard\Step::make('Upload CSV')
                        // Only the new-CSV path uploads here; the existing-test path
                        // skips this step (its data came from the chosen test).
                        ->visible(fn (Forms\Get $get): bool => ($get('test_source') ?? 'new') === 'new')
                        ->schema([
                            Forms\Components\FileUpload::make('csv_path')
                                ->label('CSV File')
                                ->acceptedFileTypes(['text/csv', '.csv'])
                                ->directory('csv')
                                ->disk('public')
                                ->maxSize(10240)
                                ->helperText('After uploading your CSV, click the button below to generate AI interpretations.')
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('csv_stored_path')
                                ->hidden(),
                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('process_csv')
                                    ->label('Process CSV & Generate AI Content')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('primary')
                                    ->action(function (Forms\Get $get, Forms\Set $set) {
                                        $csvPath = $get('csv_path');

                                        Log::info('Process CSV button clicked', [
                                            'type' => gettype($csvPath),
                                            'value' => is_object($csvPath) ? get_class($csvPath) : $csvPath,
                                        ]);

                                        // Extract TemporaryUploadedFile from array
                                        if (is_array($csvPath)) {
                                            $csvPath = array_values($csvPath)[0] ?? null;
                                        }

                                        if (empty($csvPath)) {
                                            Notification::make()
                                                ->title('Please upload a CSV file first')
                                                ->danger()
                                                ->send();
                                            return;
                                        }

                                        // Resolve the file path depending on what we got
                                        $filePath = null;

                                        if ($csvPath instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                            // Get the real temp path for parsing
                                            $filePath = $csvPath->getRealPath();

                                            // Store the file to public disk for permanent storage
                                            $storedPath = $csvPath->store('csv', 'public');
                                            $set('csv_stored_path', $storedPath);

                                            Log::info('Process CSV: TemporaryUploadedFile resolved', [
                                                'real_path' => $filePath,
                                                'stored_path' => $storedPath,
                                            ]);
                                        } elseif (is_string($csvPath)) {
                                            // Already a stored path (e.g. when editing)
                                            $filePath = Storage::disk('public')->path($csvPath);

                                            Log::info('Process CSV: String path resolved', [
                                                'csv_path' => $csvPath,
                                                'file_path' => $filePath,
                                            ]);
                                        }

                                        if (!$filePath || !file_exists($filePath)) {
                                            Notification::make()
                                                ->title('CSV file not found on disk')
                                                ->danger()
                                                ->send();
                                            return;
                                        }

                                        Notification::make()
                                            ->title('Processing CSV and generating AI interpretations...')
                                            ->body('This may take a moment.')
                                            ->info()
                                            ->send();

                                        // Parse CSV via the extracted helper. It
                                        // returns the same parse blob as before
                                        // (under 'csv_data'), so every downstream
                                        // $results[...] reference is unchanged.
                                        $csvParser = new CsvParserService();
                                        $results = (new \App\Services\LabResultParser($csvParser))
                                            ->fromPath($filePath)['csv_data'];

                                        Log::info('Process CSV button - parse results', [
                                            'phylum_totals' => $results['phylum_totals'],
                                            'diversity_score' => $results['diversity_score'],
                                        ]);

                                        $set('phylum_data', $results['phylum_totals']);
                                        $set('diversity_score', $results['diversity_score']);
                                        $set('csv_data', $results);

                                        // Set CSV-calculated fields
                                        $set('species_richness', $results['species_richness']);
                                        $set('dysbiosis_score', $results['dysbiosis_score']);
                                        $set('microbiome_classification', $results['microbiome_classification']);

                                        // Phase 3c: the AI + product/plan half is now the
                                        // shared ReportGeneration helper (same logic, reused
                                        // by the existing-test and from-test entry points).
                                        $pet = \App\Models\Pet::find($get('pet_id'));

                                        $interpretations = ReportGeneration::interpretationColumns(
                                            $results['phylum_totals'],
                                            $results['diversity_score'],
                                            $pet,
                                            // Notes history as of the report date being entered.
                                            $get('report_date'),
                                        );
                                        foreach ($interpretations as $key => $value) {
                                            $set($key, $value);
                                        }

                                        $selection = ReportGeneration::productSelection(
                                            $results['phylum_totals'],
                                            $results['diversity_score'],
                                        );
                                        $set('catalog_product_ids', $selection['catalog_product_ids']);
                                        $set('plan_id', $selection['plan_id']);

                                        $allEmpty = collect($interpretations)->every(fn ($val) => empty($val));
                                        if ($allEmpty) {
                                            Notification::make()
                                                ->title('AI interpretations returned empty')
                                                ->body('Check your OpenAI API key and credits. You can fill in interpretations manually on the next step.')
                                                ->warning()
                                                ->persistent()
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->title('CSV parsed and AI interpretations generated')
                                                ->body('Click Next to review the generated content.')
                                                ->success()
                                                ->send();
                                        }
                                    }),
                            ])->columnSpanFull(),
                        ]),

                    Forms\Components\Wizard\Step::make('AI Interpretation')
                        ->schema([
                            Forms\Components\Textarea::make('ai_summary')
                                ->label('Overall Summary')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('ai_bacteroidetes_interpretation')
                                ->label('Bacteroidetes Interpretation')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('ai_firmicutes_interpretation')
                                ->label('Firmicutes Interpretation')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('ai_fusobacteria_interpretation')
                                ->label('Fusobacteria Interpretation')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('ai_proteobacteria_interpretation')
                                ->label('Proteobacteria Interpretation')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('ai_diversity_interpretation')
                                ->label('Diversity Interpretation')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('vet_notes')
                                ->label('Additional Notes')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('vet_summary')
                                ->label('Veterinary Summary')
                                ->rows(4)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('goal')
                                ->label('Goal')
                                ->helperText('A short, personalised goal for this pet. Manually entered for now; will be AI-generated later.')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('recommended_actions')
                                ->label('Recommended Actions')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Select::make('score_gut_wall')
                                ->label('Gut Wall Integrity Score')
                                ->options([
                                    'Very High' => 'Very High',
                                    'High' => 'High',
                                    'Medium' => 'Medium',
                                    'Low' => 'Low',
                                ]),
                            Forms\Components\Select::make('score_skin_allergy')
                                ->label('Skin & Allergy Risk Score')
                                ->options([
                                    'Very High' => 'Very High',
                                    'High' => 'High',
                                    'Medium' => 'Medium',
                                    'Low' => 'Low',
                                ]),
                            Forms\Components\Select::make('score_behaviour_mood')
                                ->label('Behaviour & Mood Score')
                                ->options([
                                    'Very High' => 'Very High',
                                    'High' => 'High',
                                    'Medium' => 'Medium',
                                    'Low' => 'Low',
                                ]),
                            Forms\Components\Select::make('score_gut_barrier')
                                ->label('Gut Barrier & Metabolic Score')
                                ->options([
                                    'Very High' => 'Very High',
                                    'High' => 'High',
                                    'Medium' => 'Medium',
                                    'Low' => 'Low',
                                ]),
                            Forms\Components\Select::make('score_gas_digestive')
                                ->label('Gas & Digestive Comfort Score')
                                ->options([
                                    'Very High' => 'Very High',
                                    'High' => 'High',
                                    'Medium' => 'Medium',
                                    'Low' => 'Low',
                                ]),
                            Forms\Components\Select::make('score_stress_resilience')
                                ->label('Environmental Stress Score')
                                ->options([
                                    'Very High' => 'Very High',
                                    'High' => 'High',
                                    'Medium' => 'Medium',
                                    'Low' => 'Low',
                                ]),
                        ]),

                    Forms\Components\Wizard\Step::make('Products')
                        ->schema([
                            // Auto-matched catalog product IDs from the Step 2 "Process CSV"
                            // action. Kept as a hidden field (not removed) so the existing
                            // auto-match and the catalog_product_report pivot keep saving —
                            // they still feed the trigger system and the legacy fallback.
                            Forms\Components\Hidden::make('catalog_product_ids'),

                            // Subscribe-panel data, frozen at "Apply plan" time so later
                            // edits to the plan template don't change this report.
                            Forms\Components\Hidden::make('subscription_snapshot'),

                            // ── Plan selection ──────────────────────────────────────
                            Forms\Components\Select::make('plan_id')
                                ->label('Plan')
                                ->options(fn (): array => Plan::enabled()->orderBy('position')->pluck('name', 'id')->all())
                                ->searchable()
                                ->live()
                                ->helperText('Pick the plan for this pet, then click "Apply plan" to load its steps. A recommendation is pre-selected from the fired triggers when a CSV has been processed. (Species filtering is inactive — the app is dog-only.)'),

                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('apply_plan')
                                    ->label('Apply plan')
                                    ->icon('heroicon-o-clipboard-document-list')
                                    ->color('primary')
                                    ->action(function (Forms\Get $get, Forms\Set $set) {
                                        $planId = $get('plan_id');

                                        if (blank($planId)) {
                                            Notification::make()->title('Select a plan first')->warning()->send();
                                            return;
                                        }

                                        $plan = Plan::with('steps.products.catalogProduct')->find($planId);

                                        if (! $plan) {
                                            Notification::make()->title('Plan not found')->danger()->send();
                                            return;
                                        }

                                        // Build pet findings from the current form state (the
                                        // report may not be saved yet at apply time).
                                        $pet = filled($get('pet_id')) ? \App\Models\Pet::find($get('pet_id')) : null;
                                        $owner = $pet?->client ?? (filled($get('client_id')) ? \App\Models\Client::find($get('client_id')) : null);

                                        $findings = PetFindings::build([
                                            'pet_name' => $pet?->name,
                                            'owner_name' => $owner?->name,
                                            // Part 2: notes history as of the report date.
                                            'health_notes' => $pet?->healthNotesForContext($get('report_date')),
                                            'report_date' => $get('report_date'),
                                            'diversity_score' => $get('diversity_score'),
                                            'species_richness' => $get('species_richness'),
                                            'dysbiosis_score' => $get('dysbiosis_score'),
                                            'microbiome_classification' => $get('microbiome_classification'),
                                            'phylum_data' => $get('phylum_data') ?? [],
                                        ]);

                                        // Generate the copy, then VALIDATE it against the fixed
                                        // scaffold so factual fields can never be altered.
                                        $scaffold = $plan->toScaffold($pet?->name);
                                        $modelOutput = (new OpenAiService())->generatePlanCopy($findings, $scaffold);
                                        $copy = static::validatePlanCopy($modelOutput, $scaffold);

                                        // Instantiate the plan's LOCKED structure (factual fields
                                        // straight from the plan) and overlay the validated copy.
                                        // Falls back to placeholders for any field not generated.
                                        $steps = $plan->steps->values()->map(function ($step, $i) use ($copy) {
                                            $isProse = $step->type === 'prose';
                                            $stepCopy = $copy['steps'][$i] ?? null;

                                            return [
                                                'type' => $step->type,
                                                'title' => $step->step_title,
                                                'stage_label' => $step->stage_label,
                                                'body' => $isProse ? ($stepCopy['body'] ?? $step->body) : null,
                                                'tip' => $isProse ? ($stepCopy['tip'] ?? $step->tip) : null,
                                                'products' => $isProse ? [] : $step->products->values()->map(function ($p, $j) use ($stepCopy) {
                                                    $how = $stepCopy['products'][$j] ?? '';

                                                    return [
                                                        'catalog_product_id' => $p->catalog_product_id,
                                                        'duration' => $p->duration,
                                                        'quantity' => $p->quantity,
                                                        'dose' => $p->dose,
                                                        'inclusion' => $p->inclusion,
                                                        'how_it_helps' => $how !== '' ? $how : '[copy to be generated]',
                                                    ];
                                                })->all(),
                                            ];
                                        })->all();

                                        $set('steps', $steps);
                                        $set('plan_intro', $copy['intro'] !== '' ? $copy['intro'] : '[intro to be generated]');

                                        // Freeze the subscribe panel with product prices as-now.
                                        $set('subscription_snapshot', [
                                            'available' => (bool) $plan->subscription_available,
                                            'price' => $plan->subscription_price,
                                            'billing_note' => $plan->subscription_billing_note,
                                            'saving_label' => $plan->subscription_saving_label,
                                            'url' => $plan->subscription_url,
                                            'includes' => collect($plan->subscription_includes ?? [])
                                                ->map(fn ($name) => [
                                                    'name' => $name,
                                                    'price' => optional(CatalogProduct::where('name', $name)->first())->price,
                                                ])->all(),
                                        ]);

                                        if ($copy['has_copy']) {
                                            Notification::make()
                                                ->title('Plan applied')
                                                ->body('Loaded ' . count($steps) . ' steps with generated copy. Structure is locked; edit the copy as needed.')
                                                ->success()
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->title('Plan applied (copy not generated)')
                                                ->body('AI copy was not returned — check the OpenAI API key/credits. Placeholders kept; you can edit the copy manually.')
                                                ->warning()
                                                ->persistent()
                                                ->send();
                                        }
                                    }),
                            ]),

                            Forms\Components\Textarea::make('plan_intro')
                                ->label('Plan Intro')
                                ->rows(3)
                                ->columnSpanFull()
                                ->helperText('Editable intro shown above the steps. Placeholder for now; AI-generated next phase.'),

                            // ── Instantiated steps (LOCKED structure) ───────────────
                            // Only copy fields (how_it_helps, prose body/tip) are editable.
                            // Structural fields are disabled but ->dehydrated() so they
                            // still persist on save.
                            Forms\Components\Repeater::make('steps')
                                ->label('Plan Steps (locked structure)')
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->schema([
                                    Forms\Components\Hidden::make('type'),
                                    Forms\Components\TextInput::make('title')
                                        ->label('Step Title')
                                        ->disabled()
                                        ->dehydrated(),
                                    Forms\Components\TextInput::make('stage_label')
                                        ->label('Stage Label')
                                        ->disabled()
                                        ->dehydrated(),

                                    // Prose steps: editable copy.
                                    Forms\Components\Textarea::make('body')
                                        ->label('Body (editable)')
                                        ->rows(3)
                                        ->columnSpanFull()
                                        ->visible(fn (Forms\Get $get): bool => $get('type') === 'prose'),
                                    Forms\Components\Textarea::make('tip')
                                        ->label('Tip (editable)')
                                        ->rows(2)
                                        ->columnSpanFull()
                                        ->visible(fn (Forms\Get $get): bool => $get('type') === 'prose'),

                                    // Product steps: locked products, editable how_it_helps.
                                    Forms\Components\Repeater::make('products')
                                        ->label('Products')
                                        ->visible(fn (Forms\Get $get): bool => $get('type') === 'product')
                                        ->addable(false)
                                        ->deletable(false)
                                        ->reorderable(false)
                                        ->schema([
                                            Forms\Components\Select::make('catalog_product_id')
                                                ->label('Catalog Product')
                                                ->options(fn () => CatalogProduct::active()->orderBy('name')->pluck('name', 'id')->all())
                                                ->disabled()
                                                ->dehydrated(),
                                            Forms\Components\TextInput::make('duration')
                                                ->label('Duration')
                                                ->disabled()
                                                ->dehydrated(),
                                            Forms\Components\TextInput::make('quantity')
                                                ->label('Quantity')
                                                ->disabled()
                                                ->dehydrated(),
                                            Forms\Components\TextInput::make('dose')
                                                ->label('Dose')
                                                ->disabled()
                                                ->dehydrated(),
                                            Forms\Components\Select::make('inclusion')
                                                ->label('Inclusion')
                                                ->options(['included' => 'Included', 'optional' => 'Optional'])
                                                ->disabled()
                                                ->dehydrated(),
                                            Forms\Components\Textarea::make('how_it_helps')
                                                ->label('How it will help (editable)')
                                                ->rows(2)
                                                ->columnSpanFull(),
                                        ])
                                        ->itemLabel(fn (array $state): ?string => CatalogProduct::find($state['catalog_product_id'] ?? null)?->name ?? 'Product')
                                        ->columnSpanFull(),
                                ])
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Step')
                                ->columnSpanFull(),
                        ]),
                ])
                    ->submitAction(new \Illuminate\Support\HtmlString('<button type="submit" class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 dark:bg-custom-500 dark:hover:bg-custom-400 focus-visible:ring-custom-500/50 dark:focus-visible:ring-custom-400/50" style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);">Create Report</button>'))
                    ->columnSpanFull(),

                // Per-report Klaviyo observability. Shown on edit only; the send
                // itself is the manual "Send Report" header action.
                Forms\Components\Section::make('Klaviyo')
                    ->icon('heroicon-o-paper-airplane')
                    ->schema([
                        Forms\Components\Placeholder::make('klaviyo_last_sent')
                            ->label('Last sent to Klaviyo')
                            ->content(fn (?Report $record): string => $record?->klaviyoLastSentSummary() ?? 'Not yet sent'),
                    ])
                    ->visible(fn (?Report $record): bool => $record !== null)
                    ->collapsible(),
            ]);
    }

    /**
     * Recommend a plan from the fired trigger names. The admin can still pick a
     * different plan before applying. Returns null when nothing maps cleanly.
     *   FMT                 → rebuild-renew
     *   AMR + Antimicrobic  → reset-recover
     *   AMR + Prebiotic     → restore-rebalance
     *   no triggers (green) → maintain-protect
     */
    /**
     * Load an existing test's identity + raw lab data into the wizard form state
     * (used by the existing-test path). AI/plan are generated separately.
     */
    public static function loadTestIntoForm(mixed $testId, Forms\Set $set): void
    {
        $test = Test::find($testId);
        if (! $test) {
            return;
        }

        $set('sample_id', $test->sample_id);
        $set('report_date', optional($test->report_date)->toDateString());
        $set('phylum_data', $test->phylum_data);
        $set('diversity_score', $test->diversity_score);
        $set('species_richness', $test->species_richness);
        $set('dysbiosis_score', $test->dysbiosis_score);
        $set('microbiome_classification', $test->microbiome_classification);
        $set('csv_data', $test->csv_data);
    }

    public static function recommendPlanId(array $triggers): ?int
    {
        $key = match (true) {
            in_array('FMT', $triggers, true) => 'rebuild-renew',
            in_array('AMR', $triggers, true) && in_array('Antimicrobic', $triggers, true) => 'reset-recover',
            in_array('AMR', $triggers, true) && in_array('Prebiotic', $triggers, true) => 'restore-rebalance',
            empty($triggers) => 'maintain-protect',
            default => null,
        };

        return $key ? Plan::enabled()->where('key', $key)->value('id') : null;
    }

    /**
     * Guardrail: validate the model's plan output against the fixed scaffold and
     * return ONLY the accepted copy (intro, per-product how_it_helps, prose
     * body/tip). Factual fields are NEVER taken from the model — the caller
     * always keeps the scaffold's values; any drift here is logged, not applied.
     * Structural drift (step/product count or type mismatch) discards the copy
     * for the affected part so misaligned copy can't attach to the wrong product.
     *
     * @return array{intro:string, steps:array<int,array{body:?string,tip:?string,products:array<int,string>}>, has_copy:bool}
     */
    public static function validatePlanCopy(array $model, array $scaffold): array
    {
        $factualFields = ['name', 'price', 'dose', 'duration', 'quantity', 'product_url', 'inclusion'];

        $intro = is_string($model['intro'] ?? null) ? trim($model['intro']) : '';
        $hasCopy = $intro !== '';

        $out = ['intro' => $intro, 'steps' => [], 'has_copy' => false];

        $scaffoldSteps = $scaffold['steps'] ?? [];
        $modelSteps = is_array($model['steps'] ?? null) ? array_values($model['steps']) : [];

        if (count($modelSteps) !== count($scaffoldSteps)) {
            Log::warning('Plan copy guardrail: step count drift — per-step copy discarded.', [
                'expected' => count($scaffoldSteps),
                'got' => count($modelSteps),
            ]);
            $out['has_copy'] = $hasCopy;

            return $out;
        }

        foreach (array_values($scaffoldSteps) as $i => $sStep) {
            $mStep = $modelSteps[$i] ?? [];
            $stepCopy = ['body' => null, 'tip' => null, 'products' => []];

            if (($mStep['type'] ?? null) !== ($sStep['type'] ?? null)) {
                Log::warning('Plan copy guardrail: step type drift — step copy discarded.', ['step' => $i]);
                $out['steps'][$i] = $stepCopy;
                continue;
            }

            foreach (['step_title', 'stage_label'] as $field) {
                if (self::norm($mStep[$field] ?? null) !== self::norm($sStep[$field] ?? null)) {
                    Log::warning('Plan copy guardrail: step factual drift (kept scaffold).', [
                        'step' => $i, 'field' => $field,
                    ]);
                }
            }

            if (($sStep['type'] ?? 'product') === 'prose') {
                $body = is_string($mStep['body'] ?? null) ? trim($mStep['body']) : '';
                $tip = is_string($mStep['tip'] ?? null) ? trim($mStep['tip']) : '';
                $stepCopy['body'] = $body !== '' ? $body : null;
                $stepCopy['tip'] = $tip !== '' ? $tip : null;
                if ($stepCopy['body'] !== null || $stepCopy['tip'] !== null) {
                    $hasCopy = true;
                }
                $out['steps'][$i] = $stepCopy;
                continue;
            }

            $sProducts = array_values($sStep['products'] ?? []);
            $mProducts = is_array($mStep['products'] ?? null) ? array_values($mStep['products']) : [];

            if (count($mProducts) !== count($sProducts)) {
                Log::warning('Plan copy guardrail: product count drift — step products copy discarded.', ['step' => $i]);
                $out['steps'][$i] = $stepCopy;
                continue;
            }

            foreach ($sProducts as $j => $sProd) {
                $mProd = $mProducts[$j] ?? [];

                foreach ($factualFields as $field) {
                    if (self::norm($mProd[$field] ?? null) !== self::norm($sProd[$field] ?? null)) {
                        Log::warning('Plan copy guardrail: product factual drift (kept scaffold).', [
                            'step' => $i, 'product' => $j, 'field' => $field,
                            'scaffold' => $sProd[$field] ?? null,
                            'model' => $mProd[$field] ?? null,
                        ]);
                    }
                }

                $how = is_string($mProd['how_it_helps'] ?? null) ? trim($mProd['how_it_helps']) : '';
                $stepCopy['products'][$j] = $how;
                if ($how !== '') {
                    $hasCopy = true;
                }
            }

            $out['steps'][$i] = $stepCopy;
        }

        $out['has_copy'] = $hasCopy;

        return $out;
    }

    /** Normalise a scalar for drift comparison (string-cast + trim). */
    protected static function norm($value): string
    {
        return trim((string) ($value ?? ''));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pet.name')
                    ->label('Pet')
                    ->searchable()
                    // Back-link to the Pet hub (null-guarded for orphaned reports).
                    ->url(fn (Report $record): ?string => $record->pet
                        ? PetResource::getUrl('edit', ['record' => $record->pet])
                        : null),
                Tables\Columns\TextColumn::make('pet.breed')
                    ->label('Breed')
                    ->searchable(),
                Tables\Columns\TextColumn::make('test.sample_id')
                    ->label('Sample ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    // Back-link to the Client hub (null-guarded).
                    ->url(fn (Report $record): ?string => $record->client
                        ? ClientResource::getUrl('edit', ['record' => $record->client])
                        : null),
                Tables\Columns\TextColumn::make('test.report_date')
                    ->label('Report Date')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminFormatting::reportLabel($state))
                    ->color(fn (?string $state): string => AdminFormatting::reportColor($state)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
            ])
            ->emptyStateIcon('heroicon-o-document-chart-bar')
            ->emptyStateHeading('No reports yet')
            ->emptyStateDescription('Reports appear here once generated from a pet\'s test.')
            ->filters([
                Tables\Filters\SelectFilter::make('client_id')
                    ->relationship('client', 'name')
                    ->label('Client')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('view_public')
                    ->label('View public')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (Report $record) => route('report.show', $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (Report $record) => $record->status === 'published'),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReports::route('/'),
            'create' => Pages\CreateReport::route('/create'),
            'edit' => Pages\EditReport::route('/{record}/edit'),
        ];
    }

}
