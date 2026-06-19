<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportResource\Pages;
use App\Models\CatalogProduct;
use App\Models\Plan;
use App\Models\PlanTriggerCondition;
use App\Models\Report;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\LabResultParser;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'sample_id';

    /**
     * Resolve the record title from the linked Test explicitly, rather than
     * relying on Filament reading $recordTitleAttribute ('sample_id') off the
     * report — that column was dropped and only resolves via the in-memory
     * getAttribute proxy. This keeps the title robust (and never hits the
     * dropped column in any SQL context).
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->test?->sample_id ?? $record?->slug ?? static::getModelLabel();
    }

    public static function getGloballySearchableAttributes(): array
    {
        // sample_id now lives on the linked Test; search the relationship path
        // (Filament JOINs to tests), and the title resolves via getRecordTitle().
        return ['pet.name', 'test.sample_id', 'client.email'];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Post-create "done" state. After the wizard submits, the user is
                // redirected to the edit page with ?created=1, which EditReport
                // surfaces as $livewire->justCreated — so instead of silently
                // landing back on step 1, a clear confirmation with next actions
                // is shown above the form. Hidden on plain edits and on create.
                Forms\Components\Section::make('Report created')
                    ->icon('heroicon-o-check-circle')
                    ->description('Your report is ready. Choose what to do next.')
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\EditReport && $livewire->justCreated)
                    ->schema([
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('done_view_report')
                                ->label('View report')
                                ->icon('heroicon-o-eye')
                                ->color('info')
                                ->url(fn (?Report $record): ?string => $record ? route('report.show', $record->slug) : null)
                                ->openUrlInNewTab(),
                            Forms\Components\Actions\Action::make('done_publish')
                                ->label('Publish')
                                ->icon('heroicon-o-globe-alt')
                                ->color('success')
                                ->visible(fn (?Report $record): bool => $record?->status === 'draft')
                                ->requiresConfirmation()
                                ->modalHeading('Publish Report')
                                ->modalDescription('This will make the report publicly accessible. Continue?')
                                ->action(function (?Report $record, $livewire): void {
                                    $record->update(['status' => 'published']);
                                    $livewire->fillForm();
                                    Notification::make()->title('Report published')->success()->send();
                                }),
                            Forms\Components\Actions\Action::make('done_edit_report')
                                ->label('Edit report')
                                ->icon('heroicon-o-pencil-square')
                                ->color('gray')
                                ->action(fn ($livewire): bool => $livewire->justCreated = false),
                        ]),
                    ]),

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
                                    Forms\Components\TextInput::make('shopify_client_id')
                                        ->label('Shopify Client ID')
                                        ->maxLength(255)
                                        ->helperText('Reference ID from Shopify (optional)'),
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
                                            'Kibble' => 'Kibble',
                                            'Mixed' => 'Mixed',
                                            'Other' => 'Other',
                                        ]),
                                    // Mirror the full pet-create form: an optional first
                                    // health-log entry (note and/or weight), both blank ⇒
                                    // no entry. These are transient (not Pet columns).
                                    Forms\Components\Textarea::make('initial_note')
                                        ->label('Initial note')
                                        ->helperText('Optional. Recorded as the first health-log entry, dated today.')
                                        ->rows(3),
                                    Forms\Components\TextInput::make('initial_weight_kg')
                                        ->label('Initial weight (kg)')
                                        ->helperText('Optional. Recorded with the first health-log entry.')
                                        ->numeric()
                                        ->step(0.01)
                                        ->minValue(0),
                                    Forms\Components\TextInput::make('shopify_pet_id')
                                        ->label('Shopify Pet ID')
                                        ->maxLength(255)
                                        ->helperText('Reference ID from Shopify (optional)'),
                                ])
                                ->createOptionUsing(function (array $data, Forms\Get $get): int {
                                    $data['client_id'] = $get('client_id');

                                    // Lift the transient first-entry fields out before the
                                    // Pet is created (they are not Pet columns), then write
                                    // them to the health-notes log — same as CreatePet.
                                    $initialNote = $data['initial_note'] ?? null;
                                    $initialWeight = $data['initial_weight_kg'] ?? null;
                                    unset($data['initial_note'], $data['initial_weight_kg']);

                                    $pet = \App\Models\Pet::create($data);

                                    if (filled($initialNote) || filled($initialWeight)) {
                                        $pet->healthNotes()->create([
                                            'date' => today(),
                                            'note' => filled($initialNote) ? $initialNote : null,
                                            'weight_kg' => filled($initialWeight) ? $initialWeight : null,
                                        ]);
                                    }

                                    return $pet->getKey();
                                }),

                            // Every report attaches to a Test. Either create a new
                            // test by uploading its lab CSV, or reuse an existing
                            // report-less test for this pet. (A CSV upload IS a new
                            // test — the wording makes that explicit.)
                            Forms\Components\Radio::make('test_source')
                                ->label('Test')
                                ->options([
                                    'new' => 'New test (upload lab CSV)',
                                    'existing' => 'Use an existing test',
                                ])
                                ->default('new')
                                ->live()
                                ->dehydrated(false),

                            Forms\Components\Select::make('existing_test_id')
                                ->label('Existing test')
                                ->visible(fn (Forms\Get $get): bool => $get('test_source') === 'existing')
                                ->options(fn (Forms\Get $get): array => static::existingTestOptions(
                                    $get('pet_id'),
                                    $get('existing_test_id'),
                                ))
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
                                ->helperText('For a new test this becomes its Order ID. For an existing test it is filled in for you.'),
                            Forms\Components\DatePicker::make('report_date')
                                ->required()
                                // Default to today so it isn't filled by hand each time;
                                // overridden by the chosen test on the existing-test path.
                                ->default(now()),
                        ]),

                    Forms\Components\Wizard\Step::make('New test (CSV)')
                        // Only the new-test path uploads here; the existing-test path
                        // skips this step (its data came from the chosen test).
                        ->visible(fn (Forms\Get $get): bool => ($get('test_source') ?? 'new') === 'new')
                        ->schema([
                            Forms\Components\FileUpload::make('csv_path')
                                ->label('Lab data (CSV)')
                                ->acceptedFileTypes(['text/csv', '.csv'])
                                ->directory('csv')
                                ->disk('public')
                                ->maxSize(10240)
                                // Auto/manual line: the deterministic parse (phyla/scores)
                                // runs automatically here on upload so the metrics appear
                                // immediately and can't be forgotten. The paid OpenAI step
                                // stays a separate, explicit button below.
                                ->live()
                                ->afterStateUpdated(fn ($state, Forms\Set $set) => static::parseCsvIntoForm($state, $set))
                                ->helperText('The CSV is parsed automatically on upload — review the parsed metrics below, then click "Generate AI interpretations".')
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('csv_stored_path')
                                ->hidden(),

                            // Live read-out of the deterministic parse, so the user can
                            // sanity-check the metrics before spending the AI call.
                            Forms\Components\Placeholder::make('parsed_preview')
                                ->label('Parsed metrics')
                                ->columnSpanFull()
                                ->content(function (Forms\Get $get): string {
                                    $class = $get('microbiome_classification');
                                    if (blank($class)) {
                                        return 'No metrics yet — upload a CSV above and it will be parsed automatically.';
                                    }

                                    return 'Classification: ' . $class
                                        . '  ·  Diversity: ' . $get('diversity_score')
                                        . '  ·  Richness: ' . $get('species_richness')
                                        . '  ·  Dysbiosis: ' . $get('dysbiosis_score');
                                }),
                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('generate_ai')
                                    ->label('Generate AI interpretations & recommendations')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('primary')
                                    // The explicit, paid step — only enabled once the CSV
                                    // has been parsed (metrics present).
                                    ->disabled(fn (Forms\Get $get): bool => blank($get('phylum_data')))
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
                                            'full_price' => $plan->subscription_full_price,
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

                // Klaviyo send status is no longer a persistent bottom-of-form
                // block — it lives in the "Send Report" action's tooltip and modal
                // (EditReport header), shown only where the send actually happens.
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
    /**
     * Resolve the FileUpload state to a file and parse it into the raw metric
     * fields (phyla/scores). Pure of form/UI concerns so it can be unit-tested.
     * Tolerates the shapes the state can take (TemporaryUploadedFile, array, or an
     * already-stored path on edit). Returns [] when there is nothing to parse or
     * the file is missing; otherwise the field map keyed for form state, with
     * 'csv_stored_path' set when a fresh upload was persisted to the public disk.
     */
    public static function parseUploadedCsv(mixed $state): array
    {
        $csv = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        if (empty($csv)) {
            return [];
        }

        $storedPath = null;

        if ($csv instanceof TemporaryUploadedFile) {
            $path = $csv->getRealPath();
            // Persist to the public disk now so the parsed file survives to save
            // even if the user never re-touches the field.
            $storedPath = $csv->store('csv', 'public');
        } elseif (is_string($csv)) {
            $path = Storage::disk('public')->path($csv);
        } else {
            $path = null;
        }

        if (! $path || ! is_file($path)) {
            return [];
        }

        $results = (new LabResultParser(new CsvParserService()))->fromPath($path)['csv_data'];

        return [
            'csv_stored_path' => $storedPath,
            'phylum_data' => $results['phylum_totals'],
            'diversity_score' => $results['diversity_score'],
            'csv_data' => $results,
            'species_richness' => $results['species_richness'],
            'dysbiosis_score' => $results['dysbiosis_score'],
            'microbiome_classification' => $results['microbiome_classification'],
        ];
    }

    /**
     * The deterministic half of CSV processing: parse the uploaded lab CSV and
     * write the raw metrics into form state. Runs automatically on upload
     * (FileUpload::afterStateUpdated) so the user can't forget it; it does NO
     * AI/paid work — that stays the explicit "Generate AI interpretations" button.
     */
    public static function parseCsvIntoForm(mixed $state, Forms\Set $set): void
    {
        $csv = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        if (empty($csv)) {
            return;
        }

        $parsed = static::parseUploadedCsv($state);

        if ($parsed === []) {
            Notification::make()->title('CSV file not found on disk')->danger()->send();

            return;
        }

        foreach ($parsed as $key => $value) {
            if ($key === 'csv_stored_path' && blank($value)) {
                continue;
            }

            $set($key, $value);
        }

        Notification::make()
            ->title('CSV parsed')
            ->body($parsed['microbiome_classification']
                . ' · diversity ' . $parsed['diversity_score']
                . ' · richness ' . $parsed['species_richness'])
            ->success()
            ->send();
    }

    public static function loadTestIntoForm(mixed $testId, Forms\Set $set): void
    {
        foreach (static::testFormState($testId) as $key => $value) {
            $set($key, $value);
        }
    }

    /**
     * The wizard's step-1 form state for a given test: its identity (sample_id /
     * report_date) plus the raw lab fields. These columns were dropped from
     * `reports` (they live on the linked Test now), so neither form-fill nor any
     * other attributesToArray() path repopulates them — callers (the existing-
     * test path on create, and EditReport's fill) hydrate from here instead.
     * Returns [] for an unknown test.
     */
    public static function testFormState(mixed $testId): array
    {
        $test = Test::find($testId);
        if (! $test) {
            return [];
        }

        return [
            'sample_id' => $test->sample_id,
            'report_date' => optional($test->report_date)->toDateString(),
            'phylum_data' => $test->phylum_data,
            'diversity_score' => $test->diversity_score,
            'species_richness' => $test->species_richness,
            'dysbiosis_score' => $test->dysbiosis_score,
            'microbiome_classification' => $test->microbiome_classification,
            'csv_data' => $test->csv_data,
        ];
    }

    /**
     * Options for the existing-test select: this pet's tests that have no report
     * yet. On EDIT the report's own test already has a report (this one), so it
     * would be excluded — $currentTestId keeps it in the list so the preselected
     * value resolves to a label and stays findable when searching.
     */
    public static function existingTestOptions(mixed $petId, mixed $currentTestId = null): array
    {
        return Test::query()
            ->where('pet_id', $petId)
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('reports')
                ->orWhere('id', $currentTestId))
            ->orderByDesc('report_date')
            ->get()
            ->mapWithKeys(fn (Test $t): array => [$t->id => trim(
                $t->order_id
                . ($t->report_date ? ' · ' . $t->report_date->format('j M Y') : '')
                . ($t->microbiome_classification ? ' · ' . $t->microbiome_classification : '')
            )])
            ->all();
    }

    public static function recommendPlanId(array $triggers): ?int
    {
        $plans = static::recommendationPlans();

        // No triggers fired → the configured fallback plan (if any). Modelled as a
        // flag, never an empty condition set (which would match everything).
        if (empty($triggers)) {
            return $plans->firstWhere('is_fallback', true)?->id;
        }

        // First plan (by match_priority, then id) with ANY satisfied condition wins.
        foreach ($plans as $plan) {
            foreach ($plan->triggerConditions as $condition) {
                $required = $condition->required_triggers ?? [];

                // AND within a row; an empty set never auto-matches (guard).
                if ($required !== [] && array_diff($required, $triggers) === []) {
                    return $plan->id;
                }
            }
        }

        // Triggers fired but matched no plan → no recommendation.
        return null;
    }

    /**
     * Enabled plans in recommendation precedence: ordered by match_priority
     * (lower checked first), then id as a deterministic tiebreak when two plans
     * share a priority. Conditions eager-loaded for the matcher/explainer.
     */
    protected static function recommendationPlans(): \Illuminate\Database\Eloquent\Collection
    {
        return Plan::enabled()
            ->with('triggerConditions')
            ->orderBy('match_priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * A read-only, human-readable view of the CONFIGURED recommendation
     * precedence — now DATA-DRIVEN (Phase 2), derived from plan_trigger_conditions
     * + plans.is_fallback / match_priority. List order is the match order (first
     * match wins): condition plans by match_priority, then the fallback plan last
     * (chosen only when no triggers fire). A result that fires triggers but
     * matches no condition plan yields null. Resilient to a missing plans table.
     *
     * @return list<array{order:int, key:string, condition:string}>
     */
    public static function planRecommendationRules(): array
    {
        try {
            $plans = Plan::query()
                ->with('triggerConditions')
                ->orderBy('match_priority')
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $rules = [];
        $order = 0;

        foreach ($plans as $plan) {
            $sets = $plan->triggerConditions
                ->map(fn (PlanTriggerCondition $c): array => array_values($c->required_triggers ?? []))
                ->filter(fn (array $set): bool => $set !== [])
                ->values();

            if ($sets->isEmpty()) {
                continue;
            }

            $rules[] = [
                'order' => ++$order,
                'key' => $plan->key,
                'condition' => static::describeTriggerSets($sets->all()),
            ];
        }

        if ($fallback = $plans->firstWhere('is_fallback', true)) {
            $rules[] = [
                'order' => ++$order,
                'key' => $fallback->key,
                'condition' => 'No triggers fire (default fallback)',
            ];
        }

        return $rules;
    }

    /**
     * Human phrasing for a plan's trigger condition sets. A single set reads
     * "A trigger fires" / "A and B both fire" / "A, B and C all fire"; multiple
     * sets (OR) are joined with " — or — ".
     *
     * @param  list<list<string>>  $sets
     */
    protected static function describeTriggerSets(array $sets): string
    {
        $parts = array_map(static function (array $set): string {
            $set = array_values($set);
            $count = count($set);

            if ($count <= 1) {
                return ($set[0] ?? '?') . ' trigger fires';
            }

            if ($count === 2) {
                return $set[0] . ' and ' . $set[1] . ' both fire';
            }

            $last = array_pop($set);

            return implode(', ', $set) . ' and ' . $last . ' all fire';
        }, $sets);

        return implode(' — or — ', $parts);
    }

    /**
     * The precedence rule for a given plan key, or null when that plan is never
     * auto-recommended (it can still be chosen manually in the report builder).
     *
     * @return array{order:int, key:string, condition:string}|null
     */
    public static function planRecommendationRuleFor(?string $key): ?array
    {
        foreach (static::planRecommendationRules() as $rule) {
            if ($rule['key'] === $key) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * A read-only HTML explainer of the configured plan-recommendation decision
     * flow, surfaced on the Settings → Trigger Rules tab. Reads the editable
     * config (planRecommendationRules) and spells out the no-match→null vs
     * no-triggers→fallback distinction.
     */
    public static function planRecommendationExplainerHtml(): HtmlString
    {
        try {
            $names = Plan::query()->pluck('name', 'key')->all();
        } catch (\Throwable) {
            $names = [];
        }

        $rows = '';
        foreach (static::planRecommendationRules() as $rule) {
            $name = $names[$rule['key']] ?? null;
            $target = $name
                ? e($name) . ' <span style="color:#9ca3af;">(' . e($rule['key']) . ')</span>'
                : e($rule['key']);
            $rows .= '<li style="margin:4px 0;"><strong>' . e($rule['condition']) . '</strong> &rarr; ' . $target . '</li>';
        }

        return new HtmlString(
            '<div style="font-size:13px; color:#374151; line-height:1.6;">'
            . '<p style="margin:0 0 8px;">After the product triggers above fire, the report builder selects <strong>one</strong> plan using the rules below, <strong>checked top to bottom — the first match wins</strong>. This order is the recommendation precedence (each plan&rsquo;s <em>match priority</em>); it is <em>not</em> the same as a plan&rsquo;s display position.</p>'
            . '<ol style="margin:0 0 8px 18px; padding:0;">' . $rows . '</ol>'
            . '<p style="margin:0; color:#6b7280;">If triggers fire but match none of the condition rules above, <strong>no plan is auto-recommended</strong> — the report is left blank for manual selection. The default (fallback) plan applies only when <strong>no triggers fire at all</strong>.</p>'
            . '</div>'
        );
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
